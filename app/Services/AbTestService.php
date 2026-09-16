<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Database\Connection;
use App\Support\TenantContext;

/**
 * A/B tests on a campaign's subject line.
 *
 * The interesting decision here is what counts as a winner, and the honest
 * answer for a business with 800 contacts is usually "we cannot tell". Most
 * tools will happily declare 4.1% the winner over 3.8% on a sample of two
 * hundred, which is noise dressed as insight, and the customer changes their
 * whole approach on the strength of it.
 *
 * So there are two gates before anything is called a winner:
 *
 *  1. ENOUGH PEOPLE. Below a floor, no result is reported at all.
 *  2. ENOUGH DIFFERENCE. The gap has to be bigger than what chance alone would
 *     produce at this sample size, using a two-proportion z-test. Not because
 *     small businesses need statistics, but because the alternative is telling
 *     them something untrue with a confident face.
 *
 * When neither variant wins, that is said plainly — "too close to call, and
 * here is what would have to change" — and the variant that went out first is
 * used for the remainder, because doing nothing is not an option once half the
 * list has already been mailed.
 *
 * Clicks decide it, never opens.
 */
final class AbTestService
{
    /** Below this many delivered per variant, no winner is declared. Ever. */
    private const MINIMUM_PER_VARIANT = 100;

    /** 95% confidence, two-tailed. */
    private const Z_THRESHOLD = 1.96;

    public function __construct(
        private readonly Connection $connection,
        private readonly CampaignService $campaigns,
        private readonly AuthManager $auth,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Set up a subject-line test on a campaign.
     *
     * @param array<int,string> $subjects
     */
    public function create(int $campaignId, array $subjects, int $samplePercentage = 20): int
    {
        $this->auth->authorise('campaigns.edit');

        $campaign = $this->campaigns->find($campaignId);

        if (!in_array((string) $campaign['status'], CampaignService::EDITABLE_STATES, true)) {
            throw new ValidationException([
                'campaign' => ['You can only set up a test while the campaign is still a draft.'],
            ]);
        }

        $subjects = array_values(array_filter(array_map(
            static fn ($s): string => trim(mb_substr((string) $s, 0, 255)),
            $subjects
        )));

        if (count($subjects) < 2) {
            throw new ValidationException(['subjects' => ['A test needs at least two subject lines.']]);
        }

        $subjects = array_slice($subjects, 0, 4);
        $sample   = max(10, min(50, $samplePercentage));

        $variants = [];

        foreach ($subjects as $index => $subject) {
            $variants[] = ['key' => chr(65 + $index), 'subject' => $subject];
        }

        $now = $this->clock->nowString();

        $id = $this->connection->table('campaign_ab_tests')->insert([
            'organisation_id'   => $this->tenant->organisationId(),
            'campaign_id'       => $campaignId,
            'test_type'         => 'subject',
            'variants'          => json_encode($variants, JSON_UNESCAPED_SLASHES),
            'sample_percentage' => $sample,
            // Clicks, not opens. An open may be a mail server fetching an image.
            'winner_metric'     => 'click_rate',
            'status'            => 'pending',
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $this->connection->table('campaigns')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('id', '=', $campaignId)
            ->update(['ab_test_id' => $id, 'updated_at' => $now]);

        $this->audit->log('ab_test_created', 'campaign', $campaignId, null, [
            'variants' => count($variants),
            'sample'   => $sample,
        ]);

        return $id;
    }

    /** @return array<string,mixed>|null */
    public function forCampaign(int $campaignId): ?array
    {
        $test = $this->connection->table('campaign_ab_tests')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('campaign_id', '=', $campaignId)
            ->first();

        if ($test === null) {
            return null;
        }

        $test['variants'] = json_decode((string) $test['variants'], true) ?: [];
        $test['results']  = $this->results($campaignId, $test['variants']);

        return $test;
    }

    /**
     * Which subject each recipient in the sample gets.
     *
     * Round-robin over the sample rather than random, so a small test still
     * splits evenly. Randomising 200 people can easily give 120/80, and then the
     * comparison is between two different-sized groups before anybody has opened
     * anything.
     *
     * @param array<int,array<string,mixed>> $variants
     * @return array<int,string> recipient index => variant key
     */
    public function assign(array $variants, int $sampleSize): array
    {
        $keys       = array_map(static fn (array $v): string => (string) $v['key'], $variants);
        $assignment = [];

        for ($i = 0; $i < $sampleSize; $i++) {
            $assignment[$i] = $keys[$i % count($keys)];
        }

        return $assignment;
    }

    /**
     * Count how each variant did.
     *
     * @param array<int,array<string,mixed>> $variants
     * @return array<int,array<string,mixed>>
     */
    public function results(int $campaignId, array $variants): array
    {
        $rows = [];

        foreach ($variants as $variant) {
            $key = (string) $variant['key'];

            $row = $this->connection->selectOne(
                "SELECT SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END) AS sent,
                        SUM(CASE WHEN status = 'delivered' OR opened_at IS NOT NULL OR clicked_at IS NOT NULL
                                 THEN 1 ELSE 0 END) AS delivered,
                        SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                        SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked
                 FROM email_messages
                 WHERE organisation_id = ? AND campaign_id = ? AND ab_variant = ?",
                [$this->tenant->organisationId(), $campaignId, $key]
            ) ?? [];

            $delivered = (int) ($row['delivered'] ?? 0);
            $clicked   = (int) ($row['clicked'] ?? 0);

            $rows[] = [
                'key'        => $key,
                'subject'    => (string) $variant['subject'],
                'sent'       => (int) ($row['sent'] ?? 0),
                'delivered'  => $delivered,
                'opened'     => (int) ($row['opened'] ?? 0),
                'clicked'    => $clicked,
                'click_rate' => $delivered > 0 ? round($clicked / $delivered * 100, 2) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * Decide — or say honestly that we cannot.
     *
     * @return array{decided:bool,winner:?string,headline:string,detail:string,needed:?int}
     */
    public function evaluate(int $campaignId): array
    {
        $test = $this->forCampaign($campaignId);

        if ($test === null) {
            throw HttpException::notFound();
        }

        $results = $test['results'];

        usort($results, static fn (array $a, array $b): int => $b['click_rate'] <=> $a['click_rate']);

        $best   = $results[0];
        $second = $results[1] ?? null;

        if ($second === null) {
            return $this->undecided('There is only one version to judge.', null);
        }

        $smallest = min($best['delivered'], $second['delivered']);

        if ($smallest < self::MINIMUM_PER_VARIANT) {
            // The honest answer for most small lists. Saying it costs nothing;
            // not saying it costs somebody a change of strategy based on noise.
            return $this->undecided(
                'Not enough people to tell yet',
                'Each version needs to reach at least ' . self::MINIMUM_PER_VARIANT
                    . ' people before a difference means anything. The smaller one has reached '
                    . number_format($smallest) . '. Below that, the gap you are looking at is '
                    . 'as likely to be luck as anything else.',
                self::MINIMUM_PER_VARIANT - $smallest
            );
        }

        $z = $this->zScore(
            $best['clicked'],
            $best['delivered'],
            $second['clicked'],
            $second['delivered']
        );

        if ($z < self::Z_THRESHOLD) {
            return $this->undecided(
                'Too close to call',
                '"' . $best['subject'] . '" got ' . $best['click_rate'] . '% and "'
                    . $second['subject'] . '" got ' . $second['click_rate'] . '%. That gap is small '
                    . 'enough that it could easily be chance. We will send the rest using the first '
                    . 'version — but do not change how you write on the strength of this.',
                null
            );
        }

        $this->connection->table('campaign_ab_tests')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('id', '=', (int) $test['id'])
            ->update([
                'winning_variant' => (string) $best['key'],
                'status'          => 'decided',
                'decided_at'      => $this->clock->nowString(),
                'results'         => json_encode($results, JSON_UNESCAPED_SLASHES),
                'updated_at'      => $this->clock->nowString(),
            ]);

        $this->audit->log('ab_test_decided', 'campaign', $campaignId, null, [
            'winner'     => (string) $best['key'],
            'click_rate' => $best['click_rate'],
        ]);

        return [
            'decided'  => true,
            'winner'   => (string) $best['key'],
            'headline' => '"' . $best['subject'] . '" won',
            'detail'   => $best['click_rate'] . '% of people who got it pressed something, against '
                . $second['click_rate'] . '% for the other one. With '
                . number_format($best['delivered']) . ' and ' . number_format($second['delivered'])
                . ' people, that gap is big enough to be real rather than luck. '
                . 'The rest of the list will get this version.',
            'needed'   => null,
        ];
    }

    // ------------------------------------------------------------ internals

    /**
     * Two-proportion z-test.
     *
     * The whole point of the class in one function: is this gap bigger than
     * chance would produce at this sample size?
     */
    private function zScore(int $successesA, int $totalA, int $successesB, int $totalB): float
    {
        if ($totalA <= 0 || $totalB <= 0) {
            return 0.0;
        }

        $pA     = $successesA / $totalA;
        $pB     = $successesB / $totalB;
        $pooled = ($successesA + $successesB) / ($totalA + $totalB);

        $standardError = sqrt($pooled * (1 - $pooled) * (1 / $totalA + 1 / $totalB));

        if ($standardError <= 0.0) {
            return 0.0;
        }

        return abs($pA - $pB) / $standardError;
    }

    /** @return array{decided:bool,winner:?string,headline:string,detail:string,needed:?int} */
    private function undecided(string $headline, ?string $detail, ?int $needed = null): array
    {
        return [
            'decided'  => false,
            'winner'   => null,
            'headline' => $headline,
            'detail'   => $detail ?? '',
            'needed'   => $needed,
        ];
    }
}
