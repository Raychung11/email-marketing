<?php

declare(strict_types=1);

namespace App\Services;

use App\AI\AiGuard;
use App\AI\AiRequest;
use App\Core\Clock;
use App\Core\Config;
use App\Database\Connection;
use App\Support\TenantContext;

/**
 * "How did that campaign go, and what should I do differently?"
 *
 * One rule shapes this whole class: THE MODEL MAY EXPLAIN, IT MAY NOT COUNT.
 *
 * Every figure comes from AnalyticsService and is handed to the model as fact.
 * What comes back is prose, and before any of it is shown, every number in that
 * prose is checked against the figures we supplied. A sentence containing a
 * figure we cannot account for is dropped, not softened — because the failure
 * mode here is not a clumsy sentence, it is a small business owner repeating
 * "your campaign brought in $4,200" to their accountant when nothing of the sort
 * happened.
 *
 * That check is cheap, it is testable, and it is the difference between a tool
 * that summarises and a tool that fabricates.
 */
final class AiInsightService
{
    /**
     * Numbers the model may use without us having supplied them.
     *
     * Small integers turn up structurally — "three things to try", "the first
     * link" — and flagging those would make the check useless through noise.
     * Anything above this has to come from the facts.
     */
    private const FREE_NUMBERS = 10;

    /** How long a stored review stays fresh before it is worth regenerating. */
    private const REVIEW_TTL_DAYS = 30;

    public function __construct(
        private readonly AiService $ai,
        private readonly AiGuard $guard,
        private readonly AnalyticsService $analytics,
        private readonly CampaignService $campaigns,
        private readonly AuthManager $auth,
        private readonly Connection $connection,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly AuditService $audit,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->ai->isAvailable();
    }

    // ------------------------------------------------------- campaign review

    /**
     * The stored review for a campaign, if there is one.
     *
     * @return array<string,mixed>|null
     */
    public function storedReview(int $campaignId): ?array
    {
        $row = $this->connection->table('ai_recommendations')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('recommendation_type', '=', 'campaign_review')
            ->where('action_url', '=', '/campaigns/' . $campaignId)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($row === null) {
            return null;
        }

        $row['metrics'] = json_decode((string) ($row['metrics'] ?? '[]'), true) ?: [];

        return $row;
    }

    /**
     * Write a review of a finished campaign.
     *
     * @return array<string,mixed>
     */
    public function reviewCampaign(int $campaignId): array
    {
        $this->auth->authorise('ai.use');

        $campaign = $this->campaigns->find($campaignId);
        $facts    = $this->campaignFacts($campaignId, $campaign);

        if ($facts['sent'] === 0) {
            return $this->refusal('This campaign has not been sent yet, so there is nothing to review.');
        }

        $response = $this->ai->structured(
            new AiRequest(
                feature: 'campaign_analysis',
                systemPrompt: $this->reviewRules(),
                userPrompt: $this->factsToPrompt($campaign, $facts),
                context: $facts,
                temperature: 0.4,
                maxTokens: 1200,
                userId: $this->auth->id(),
                relatedEntityType: 'campaign',
                relatedEntityId: $campaignId,
            ),
            $this->reviewSchema()
        );

        if (!$response->ok) {
            return $this->refusal(
                'The AI could not write a summary just now. The numbers above are still correct.'
            );
        }

        $review = $this->verifyReview($response->data, $facts);

        $this->store($campaignId, $review, $facts);

        $this->audit->logAi('ai_campaign_reviewed', 'campaign', $campaignId, [
            'unverified_figures' => count($review['dropped']),
        ]);

        return $review;
    }

    // -------------------------------------------------------- the figure check

    /**
     * Check prose against a set of facts, dropping any sentence that quotes a
     * figure we did not supply.
     *
     * Exposed so the assistant uses this check rather than growing a second copy
     * of it — and a second copy is exactly how one of them quietly stops
     * checking.
     *
     * @param array<string,mixed> $facts
     * @return array{text:string,dropped:int}
     */
    public function verifyProse(string $text, array $facts): array
    {
        $dropped = [];
        $checked = $this->checkedSentences($text, $this->allowedNumbers($facts), $dropped);

        return ['text' => $checked, 'dropped' => count($dropped)];
    }

    /**
     * Drop anything containing a number we did not supply.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $facts
     * @return array<string,mixed>
     */
    private function verifyReview(array $data, array $facts): array
    {
        $allowed = $this->allowedNumbers($facts);
        $dropped = [];

        $headline = $this->checked((string) ($data['headline'] ?? ''), $allowed, $dropped, 160);
        $summary  = $this->checkedSentences((string) ($data['summary'] ?? ''), $allowed, $dropped);

        $suggestions = [];

        foreach ((array) ($data['suggestions'] ?? []) as $suggestion) {
            if (!is_string($suggestion) && !is_array($suggestion)) {
                continue;
            }

            $text = is_array($suggestion) ? (string) ($suggestion['text'] ?? '') : $suggestion;
            $text = $this->checked($text, $allowed, $dropped, 240);

            if ($text !== '') {
                $suggestions[] = $text;
            }

            if (count($suggestions) >= 4) {
                break;
            }
        }

        return $this->guard->label([
            // A measured headline if the model's one did not survive. The user
            // still gets a straight answer.
            'headline'    => $headline !== '' ? $headline : $this->measuredHeadline($facts),
            'summary'     => $summary,
            'suggestions' => $suggestions,
            'dropped'     => $dropped,
            'facts'       => $facts,
            'ok'          => true,
        ]);
    }

    /**
     * Every number we told the model, in the forms it might write them back.
     *
     * @param array<string,mixed> $facts
     * @return array<int,string>
     */
    private function allowedNumbers(array $facts): array
    {
        $allowed = [];

        array_walk_recursive($facts, static function ($value) use (&$allowed): void {
            if (!is_int($value) && !is_float($value)) {
                return;
            }

            // Written plainly, with thousands separators, and rounded — a model
            // saying "3%" about 3.14% is reporting our figure, not inventing one.
            $allowed[] = (string) $value;
            $allowed[] = number_format((float) $value);
            $allowed[] = (string) round((float) $value);
            $allowed[] = (string) round((float) $value, 1);
            $allowed[] = (string) round((float) $value, 2);
            $allowed[] = number_format((float) $value, 1);
            $allowed[] = number_format((float) $value, 2);
        });

        for ($i = 0; $i <= self::FREE_NUMBERS; $i++) {
            $allowed[] = (string) $i;
        }

        return array_values(array_unique($allowed));
    }

    /**
     * @param array<int,string> $allowed
     * @param array<int,string> $dropped collects what was thrown away, by reference
     */
    private function checked(string $text, array $allowed, array &$dropped, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return '';
        }

        $unknown = $this->unverifiableNumbers($text, $allowed);

        if ($unknown !== []) {
            $dropped[] = $text;

            return '';
        }

        return mb_substr($text, 0, $max);
    }

    /**
     * Sentence by sentence, so one bad figure does not throw away a good
     * paragraph.
     *
     * @param array<int,string> $allowed
     * @param array<int,string> $dropped
     */
    private function checkedSentences(string $text, array $allowed, array &$dropped): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
        $kept      = [];

        foreach ($sentences as $sentence) {
            $clean = $this->checked($sentence, $allowed, $dropped, 400);

            if ($clean !== '') {
                $kept[] = $clean;
            }
        }

        return mb_substr(implode(' ', $kept), 0, 1200);
    }

    /**
     * @param array<int,string> $allowed
     * @return array<int,string>
     */
    private function unverifiableNumbers(string $text, array $allowed): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $text, $matches);

        $unknown = [];

        foreach ($matches[0] as $number) {
            $bare = str_replace(',', '', $number);

            if (in_array($number, $allowed, true) || in_array($bare, $allowed, true)) {
                continue;
            }

            // A four-digit number that looks like a year is structural, not a claim.
            if (preg_match('/^(19|20)\d{2}$/', $bare) === 1) {
                continue;
            }

            $unknown[] = $number;
        }

        return $unknown;
    }

    // ------------------------------------------------------------- the facts

    /**
     * Everything measured about one campaign. This is the model's whole world.
     *
     * @param array<string,mixed> $campaign
     * @return array<string,mixed>
     */
    private function campaignFacts(int $campaignId, array $campaign): array
    {
        $funnel = $this->analytics->campaignFunnel($campaignId);
        $links  = $this->analytics->topLinks($campaignId);

        $average = $this->analytics->highlights(180);

        return [
            'sent'            => (int) $funnel['sent'],
            'delivered'       => (int) $funnel['delivered'],
            'opened'          => (int) $funnel['opened'],
            'clicked'         => (int) $funnel['clicked'],
            'bounced'         => (int) $funnel['bounced'],
            'complained'      => (int) $funnel['complained'],
            'unsubscribed'    => (int) $funnel['unsubscribed'],
            'delivery_rate'   => (float) $funnel['delivery_rate'],
            'open_rate'       => (float) $funnel['open_rate'],
            'click_rate'      => (float) $funnel['click_rate'],
            'your_average_click_rate' => (float) $average['average_click_rate'],
            'top_links'       => array_map(static fn (array $l): array => [
                'label'  => $l['label'],
                'people' => (int) $l['people'],
            ], array_slice($links, 0, 5)),
            'campaign_type'   => (string) ($campaign['campaign_type'] ?? ''),
            'subject'         => (string) ($campaign['subject'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $facts */
    private function measuredHeadline(array $facts): string
    {
        return number_format($facts['clicked']) . ' of ' . number_format($facts['delivered'])
            . ' people who received this pressed something in it.';
    }

    // ------------------------------------------------------------- storage

    /**
     * @param array<string,mixed> $review
     * @param array<string,mixed> $facts
     */
    private function store(int $campaignId, array $review, array $facts): void
    {
        $now = $this->clock->nowString();

        $this->connection->table('ai_recommendations')->insert([
            'organisation_id'     => $this->tenant->organisationId(),
            'recommendation_type' => 'campaign_review',
            'title'               => mb_substr((string) $review['headline'], 0, 200),
            'body'                => trim((string) $review['summary'] . "\n" . implode("\n", $review['suggestions'])),
            'impact'              => $this->impactFor($facts),
            // Never 'calculated' or 'observed'. The figures underneath are
            // measured; this sentence about them is not.
            'data_basis'          => 'ai_recommendation',
            'action_label'        => 'Open the campaign',
            'action_url'          => '/campaigns/' . $campaignId,
            'metrics'             => json_encode([
                'campaign_id' => $campaignId,
                'suggestions' => $review['suggestions'],
                'dropped'     => count($review['dropped']),
            ], JSON_UNESCAPED_SLASHES),
            'expires_at'          => $this->clock->now()
                ->modify('+' . self::REVIEW_TTL_DAYS . ' days')->format('Y-m-d H:i:s'),
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
    }

    /** @param array<string,mixed> $facts */
    private function impactFor(array $facts): string
    {
        // A spam complaint, or bounces above a twentieth of the send, is the kind
        // of thing somebody should read today rather than next month.
        $bounceRate = $facts['sent'] > 0 ? $facts['bounced'] / $facts['sent'] * 100 : 0.0;

        if ($facts['complained'] > 0 || $bounceRate > 5.0) {
            return 'high';
        }

        return $facts['click_rate'] < $facts['your_average_click_rate'] ? 'medium' : 'low';
    }

    /** @return array<string,mixed> */
    private function refusal(string $message): array
    {
        return $this->guard->label([
            'ok'          => false,
            'headline'    => $message,
            'summary'     => '',
            'suggestions' => [],
            'dropped'     => [],
            'facts'       => [],
        ]);
    }

    // ----------------------------------------------------------- the prompts

    private function reviewRules(): string
    {
        return implode("\n", [
            'You explain how an email campaign went to the person who runs the business.',
            'They are a plumber, a dentist, a restaurant owner. Short sentences, no jargon.',
            'Return JSON only.',
            '',
            'Absolute rules:',
            '- USE ONLY THE NUMBERS IN THE FACTS. Any other number will be deleted along with the',
            '  sentence it is in, so a made-up figure costs you the whole point you were making.',
            '- do not calculate new figures. If you want a comparison, use the ones given.',
            '- never guess at revenue, profit, or how many people bought something. You were not',
            '  told those, so you do not know them.',
            '- treat the open rate as unreliable and say so if you mention it: mail apps load',
            '  images automatically, which counts as an open even when nobody read the message.',
            '- give at most 3 suggestions, each one concrete enough to act on this week.',
            '- if the campaign did well, say so plainly instead of manufacturing a problem.',
        ]);
    }

    /**
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $facts
     */
    private function factsToPrompt(array $campaign, array $facts): string
    {
        $lines = [
            'CAMPAIGN: ' . (string) ($campaign['name'] ?? ''),
            'TYPE: ' . $facts['campaign_type'],
            'SUBJECT LINE: ' . $facts['subject'],
            '',
            'FACTS (these are measured, and the only numbers you may use):',
            '- sent: ' . $facts['sent'],
            '- arrived: ' . $facts['delivered'] . ' (' . $facts['delivery_rate'] . '%)',
            '- clicked something: ' . $facts['clicked'] . ' (' . $facts['click_rate'] . '%)',
            '- opened: ' . $facts['opened'] . ' (' . $facts['open_rate'] . '%, unreliable)',
            '- bounced: ' . $facts['bounced'],
            '- marked as spam: ' . $facts['complained'],
            '- unsubscribed: ' . $facts['unsubscribed'],
            '- this business average click rate: ' . $facts['your_average_click_rate'] . '%',
        ];

        if ($facts['top_links'] !== []) {
            $lines[] = '- links pressed:';

            foreach ($facts['top_links'] as $link) {
                $lines[] = '  - "' . $link['label'] . '": ' . $link['people'] . ' people';
            }
        }

        return implode("\n", $lines);
    }

    /** @return array<string,mixed> */
    private function reviewSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'headline'    => ['type' => 'string'],
                'summary'     => ['type' => 'string'],
                'suggestions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
