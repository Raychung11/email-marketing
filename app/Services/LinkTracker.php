<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Signer;
use App\Database\Connection;
use App\Support\TenantContext;

/**
 * Click and open tracking.
 *
 * Two design points that matter more than the mechanics:
 *
 *  1. NO OPEN REDIRECT. The redirect endpoint never takes a URL. It takes a
 *     signed token identifying a row in tracked_links, and the destination comes
 *     from that row — a URL an authenticated user put into a campaign. Even with a
 *     valid signature, an attacker cannot point the redirect anywhere new.
 *
 *  2. Unsubscribe and preference links are never wrapped. Routing the one link a
 *     recipient must be able to trust through a tracker adds a failure mode to
 *     the mechanism that protects sending reputation, for no benefit.
 *
 * Opens are tracked because customers expect the number, and treated as weak
 * evidence everywhere it is reported: mail privacy proxies pre-fetch images, so an
 * "open" may mean nothing happened at all.
 */
final class LinkTracker
{
    /** Links we never rewrite. */
    private const EXCLUDED_PATTERNS = [
        '#/unsubscribe/#i',
        '#/preferences/#i',
        '#^mailto:#i',
        '#^tel:#i',
        '#^\#/?#',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly Signer $signer,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * Rewrite links for tracking and append the open pixel.
     *
     * @param array<string,mixed> $campaign
     */
    public function rewrite(string $html, array $campaign, int $contactId, int $messageId): string
    {
        if (!(bool) $this->config->get('mail.tracking.click_wrapping', true)) {
            return $this->appendOpenPixel($html, $campaign, $contactId, $messageId);
        }

        $campaignId = (int) $campaign['id'];

        // Double-quoted hrefs only, which is what TemplateRenderer emits and what
        // the email HTML dialect (tables, inline styles, no minification) uses. A
        // hand-written single-quoted href is left untracked rather than risking a
        // regex that mangles real markup.
        $rewritten = (string) preg_replace_callback(
            '/href\s*=\s*"([^"]+)"/i',
            function (array $matches) use ($campaign, $campaignId, $contactId, $messageId): string {
                $url = html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                if ($this->shouldSkip($url)) {
                    return $matches[0];
                }

                $withUtm = $this->appendUtm($url, $campaign);
                $linkId  = $this->registerLink(
                    $campaignId > 0 ? 'campaign' : 'automation',
                    $campaignId > 0 ? $campaignId : (int) ($campaign['automation_id'] ?? 0),
                    $withUtm
                );

                $token = $this->signer->sign([
                    'o' => (int) $campaign['organisation_id'],
                    'k' => $campaignId,
                    'l' => $linkId,
                    'm' => $messageId,
                    'c' => $contactId,
                    'v' => 1,
                ]);

                return 'href="' . htmlspecialchars(url('track/click/' . $token), ENT_QUOTES, 'UTF-8') . '"';
            },
            $html
        );

        return $this->appendOpenPixel($rewritten, $campaign, $contactId, $messageId);
    }

    /**
     * Rewrite links in an automation email.
     *
     * Same machinery as a campaign, minus the campaign. Automation links are
     * registered against campaign_id 0, so a journey's click reports do not
     * pollute any campaign's numbers and cannot be mistaken for one.
     *
     * @param array<string,mixed> $organisation
     */
    public function rewriteForAutomation(
        string $html,
        array $organisation,
        int $contactId,
        int $messageId,
    ): string {
        return $this->rewrite(
            $html,
            ['id' => 0, 'organisation_id' => (int) $organisation['id'], 'utm_medium' => 'email',
             'utm_source' => 'automation'],
            $contactId,
            $messageId
        );
    }

    /**
     * UTM parameters, appended without clobbering anything the author already set.
     *
     * @param array<string,mixed> $campaign
     */
    public function appendUtm(string $url, array $campaign): string
    {
        $parameters = array_filter([
            'utm_source'   => (string) ($campaign['utm_source'] ?? 'email'),
            'utm_medium'   => (string) ($campaign['utm_medium'] ?? 'email'),
            'utm_campaign' => (string) ($campaign['utm_campaign'] ?? ''),
            'utm_content'  => (string) ($campaign['utm_content'] ?? ''),
            'utm_term'     => (string) ($campaign['utm_term'] ?? ''),
        ], static fn (string $value): bool => $value !== '');

        if ($parameters === []) {
            return $url;
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }

        $existing = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $existing);
        }

        // The author's own UTM values win: they may be running a specific
        // attribution scheme and overwriting it silently would corrupt it.
        $query = array_merge($parameters, $existing);

        $rebuilt = ($parts['scheme'] ?? 'https') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '')
            . '?' . http_build_query($query)
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

        return $rebuilt;
    }

    /**
     * Register (or find) the link row for a destination.
     *
     * Keyed on a hash of the URL so the same link in the same campaign is one row
     * with one click count, however many times it appears in the body.
     */
    public function registerLink(
        string $ownerType,
        int $ownerId,
        string $url,
        ?string $label = null,
    ): int {
        $hash = substr(hash('sha256', $url), 0, 40);

        $existing = $this->connection->table('tracked_links')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('owner_type', '=', $ownerType)
            ->where('owner_id', '=', $ownerId)
            ->where('link_hash', '=', $hash)
            ->first();

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->connection->table('tracked_links')->insert([
            'organisation_id' => $this->tenant->organisationId(),
            'owner_type'      => $ownerType,
            'owner_id'        => $ownerId,
            'link_hash'       => $hash,
            'original_url'    => $url,
            'label'           => $label,
            'created_at'      => $this->clock->nowString(),
        ]);
    }

    /**
     * Resolve a click token.
     *
     * Returns the destination from our own records. The token never carries a URL,
     * so there is nothing an attacker can substitute.
     *
     * @return array{organisation_id:int,campaign_id:int,link_id:int,message_id:?int,contact_id:?int,url:string}|null
     */
    public function resolveClick(string $token): ?array
    {
        $payload = $this->signer->verify($token);

        if ($payload === null) {
            return null;
        }

        $organisationId = (int) ($payload['o'] ?? 0);
        $linkId         = (int) ($payload['l'] ?? 0);

        if ($organisationId <= 0 || $linkId <= 0) {
            return null;
        }

        $link = $this->connection->table('tracked_links')
            ->where('id', '=', $linkId)
            ->where('organisation_id', '=', $organisationId)
            ->first();

        if ($link === null) {
            return null;
        }

        $url = (string) $link['original_url'];

        // Belt and braces: the URL came from a campaign an authenticated user
        // wrote, but a scheme check costs nothing and closes the door on a stored
        // javascript: URL from an older record.
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return [
            'organisation_id' => $organisationId,
            'campaign_id'     => (string) $link['owner_type'] === 'campaign' ? (int) $link['owner_id'] : 0,
            'link_id'         => $linkId,
            'message_id'      => isset($payload['m']) ? (int) $payload['m'] : null,
            'contact_id'      => isset($payload['c']) ? (int) $payload['c'] : null,
            'url'             => $url,
        ];
    }

    /**
     * Resolve an open token.
     *
     * @return array{organisation_id:int,campaign_id:int,message_id:?int,contact_id:?int}|null
     */
    public function resolveOpen(string $token): ?array
    {
        $payload = $this->signer->verify($token);

        if ($payload === null) {
            return null;
        }

        $organisationId = (int) ($payload['o'] ?? 0);

        if ($organisationId <= 0) {
            return null;
        }

        return [
            'organisation_id' => $organisationId,
            'campaign_id'     => (int) ($payload['k'] ?? 0),
            'message_id'      => isset($payload['m']) ? (int) $payload['m'] : null,
            'contact_id'      => isset($payload['c']) ? (int) $payload['c'] : null,
        ];
    }

    /**
     * Record an open.
     *
     * Treated as weak evidence everywhere it is reported. Mail privacy proxies
     * pre-fetch images on the recipient's behalf, so an "open" may mean a server
     * in another country looked at a pixel and the person never saw the message.
     * The number is kept because customers expect it, and clicks are weighted
     * higher in every piece of analysis that matters.
     *
     * @param array{organisation_id:int,campaign_id:int,message_id:?int,contact_id:?int} $resolved
     */
    public function recordOpen(array $resolved, ?string $ip = null, ?string $userAgent = null): bool
    {
        $messageId = (int) ($resolved['message_id'] ?? 0);

        if ($messageId <= 0) {
            return false;
        }

        $recorded = $this->recordEvent('open', $resolved, $messageId, null, null, $ip, $userAgent);

        if (!$recorded) {
            return false;
        }

        // opened_at is set once, so unique opens stay unique however many times
        // a proxy re-fetches the pixel; open_count is the raw total and is
        // labelled as such.
        //
        // `status` is deliberately untouched: it tracks what the provider did
        // with the message, and an open is not a delivery state. Engagement lives
        // in its own timestamps and counters, which is also why a missed delivery
        // webhook cannot be papered over by a pixel fetch.
        $this->connection->execute(
            'UPDATE email_messages
             SET open_count = open_count + 1,
                 opened_at = COALESCE(opened_at, ?)
             WHERE id = ? AND organisation_id = ?',
            [$this->clock->nowString(), $messageId, (int) $resolved['organisation_id']]
        );

        return true;
    }

    /**
     * Record a click.
     *
     * A click is the strongest first-party engagement signal this system has: it
     * required a person, a device and an intent, where an open requires only an
     * image request.
     *
     * @param array{organisation_id:int,campaign_id:int,link_id:int,message_id:?int,contact_id:?int,url:string} $resolved
     */
    public function recordClick(array $resolved, ?string $ip = null, ?string $userAgent = null): bool
    {
        $messageId = (int) ($resolved['message_id'] ?? 0);
        $linkId    = (int) $resolved['link_id'];

        if ($messageId <= 0) {
            return false;
        }

        // "Unique" means this message has not clicked this link before — asked of
        // the event table, which is the authoritative record, rather than of a
        // counter that a redelivery could have already moved.
        $unique = !$this->connection->table('email_events')
            ->where('email_message_id', '=', $messageId)
            ->where('event_type', '=', 'click')
            ->where('tracked_link_id', '=', $linkId)
            ->exists();

        $recorded = $this->recordEvent(
            'click',
            $resolved,
            $messageId,
            $linkId,
            (string) $resolved['url'],
            $ip,
            $userAgent
        );

        if (!$recorded) {
            return false;
        }

        $this->connection->execute(
            'UPDATE email_messages
             SET click_count = click_count + 1,
                 clicked_at = COALESCE(clicked_at, ?)
             WHERE id = ? AND organisation_id = ?',
            [$this->clock->nowString(), $messageId, (int) $resolved['organisation_id']]
        );

        $this->incrementLinkClicks($linkId, $unique);

        return true;
    }

    /**
     * Append to the event stream, de-duplicated within the minute.
     *
     * The synthesised id goes through the same unique index that makes provider
     * redeliveries idempotent. The minute bucket is what blunts the obvious
     * duplication — a client that fetches the pixel three times as it renders,
     * a preview pane, a scanner following the same link — without pretending a
     * genuine second open an hour later did not happen.
     *
     * @param array<string,mixed> $resolved
     */
    private function recordEvent(
        string $type,
        array $resolved,
        int $messageId,
        ?int $linkId,
        ?string $url,
        ?string $ip,
        ?string $userAgent,
    ): bool {
        $now = $this->clock->nowString();

        $eventId = hash('sha256', implode('|', [
            'internal',
            $type,
            (string) $messageId,
            (string) ($linkId ?? 0),
            substr($now, 0, 16), // to the minute
        ]));

        try {
            $this->connection->table('email_events')->insert([
                'organisation_id'   => (int) $resolved['organisation_id'],
                'email_message_id'  => $messageId,
                'campaign_id'       => ($resolved['campaign_id'] ?? 0) > 0 ? (int) $resolved['campaign_id'] : null,
                'contact_id'        => ($resolved['contact_id'] ?? 0) > 0 ? (int) $resolved['contact_id'] : null,
                'event_type'        => $type,
                'provider'          => 'internal',
                'provider_event_id' => $eventId,
                'clicked_url'       => $url,
                'tracked_link_id'   => $linkId,
                'ip_address'        => $ip,
                'user_agent'        => $userAgent === null ? null : substr($userAgent, 0, 255),
                'event_at'          => $now,
                'created_at'        => $now,
            ]);

            return true;
        } catch (\Throwable) {
            // The unique index rejected it: already counted this minute.
            return false;
        }
    }

    public function incrementLinkClicks(int $linkId, bool $unique): void
    {
        $this->connection->execute(
            'UPDATE tracked_links SET click_count = click_count + 1'
            . ($unique ? ', unique_click_count = unique_click_count + 1' : '')
            . ' WHERE id = ? AND organisation_id = ?',
            [$linkId, $this->tenant->organisationId()]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function linksFor(string $ownerType, int $ownerId): array
    {
        return $this->connection->select(
            'SELECT * FROM tracked_links WHERE organisation_id = ? AND owner_type = ? AND owner_id = ?
             ORDER BY click_count DESC, id',
            [$this->tenant->organisationId(), $ownerType, $ownerId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function linksForCampaign(int $campaignId): array
    {
        return $this->linksFor('campaign', $campaignId);
    }

    /** @param array<string,mixed> $campaign */
    private function appendOpenPixel(string $html, array $campaign, int $contactId, int $messageId): string
    {
        if (!(bool) $this->config->get('mail.tracking.open_pixel', true)) {
            return $html;
        }

        $token = $this->signer->sign([
            'o' => (int) $campaign['organisation_id'],
            'k' => (int) $campaign['id'],
            'm' => $messageId,
            'c' => $contactId,
            'v' => 1,
        ]);

        $pixel = '<img src="' . htmlspecialchars(url('track/open/' . $token), ENT_QUOTES, 'UTF-8') . '"'
            . ' width="1" height="1" alt="" style="display:block;border:0;height:1px;width:1px">';

        if (stripos($html, '</body>') !== false) {
            return (string) preg_replace('#</body>#i', $pixel . '</body>', $html, 1);
        }

        return $html . $pixel;
    }

    private function shouldSkip(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return true;
        }

        // An unsubstituted merge variable is not a link yet.
        if (preg_match('/^\{\{\s*[a-z_][a-z0-9_]*\s*\}\}$/i', $url) === 1) {
            return true;
        }

        foreach (self::EXCLUDED_PATTERNS as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        // Only absolute http(s) links are trackable; anything else is left alone.
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return !in_array($scheme, ['http', 'https'], true);
    }
}
