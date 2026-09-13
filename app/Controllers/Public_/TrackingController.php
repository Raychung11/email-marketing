<?php

declare(strict_types=1);

namespace App\Controllers\Public_;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ContactRepository;
use App\Repositories\OrganisationRepository;
use App\Services\LinkTracker;
use App\Support\TenantContext;

/**
 * Open and click tracking endpoints.
 *
 * Unauthenticated, because the person following the link is a recipient, not a
 * user. Authorisation is the HMAC signature on the token, and the organisation
 * comes from inside that signed payload — the one place tenancy is derived from a
 * URL, and only because we signed it ourselves and verify it before reading it.
 *
 * THE REDIRECT IS NOT AN OPEN REDIRECT. The token carries a `tracked_links` row
 * id, never a destination. The URL comes from that row — something an
 * authenticated user put into a campaign — and is re-checked to be http(s)
 * before we hand a `Location` header to a browser. A forged token fails the
 * signature; a valid token cannot be edited to point somewhere new.
 *
 * Neither endpoint ever shows an error to a recipient. A dead pixel returns a
 * pixel and a dead link returns the site, because the person on the other end
 * did nothing wrong and cannot fix our token.
 */
final class TrackingController
{
    /** A 1×1 transparent GIF. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(
        private readonly LinkTracker $links,
        private readonly OrganisationRepository $organisations,
        private readonly ContactRepository $contacts,
        private readonly \App\Automation\TriggerDispatcher $triggers,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /** GET /track/open/{token} */
    public function open(Request $request): Response
    {
        $resolved = $this->links->resolveOpen((string) $request->route('token', ''));

        if ($resolved !== null) {
            $this->withTenant((int) $resolved['organisation_id'], function () use ($resolved, $request): void {
                if ($this->links->recordOpen($resolved, $request->ip(), $request->userAgent())
                    && ($resolved['contact_id'] ?? 0) > 0
                ) {
                    $this->contacts->touchEngagement((int) $resolved['contact_id'], 'open');
                }
            });
        }

        return $this->pixel();
    }

    /** GET /track/click/{token} */
    public function click(Request $request): Response
    {
        $resolved = $this->links->resolveClick((string) $request->route('token', ''));

        if ($resolved === null) {
            // An expired or malformed token. Send them somewhere real rather than
            // showing an error page for a link they were invited to click.
            return Response::redirect((string) $this->config->get('app.url', '/'));
        }

        $this->withTenant((int) $resolved['organisation_id'], function () use ($resolved, $request): void {
            if (!$this->links->recordClick($resolved, $request->ip(), $request->userAgent())
                || ($resolved['contact_id'] ?? 0) <= 0
            ) {
                return;
            }

            $this->contacts->touchEngagement((int) $resolved['contact_id'], 'click');

            // Only on a first-counted click, so a mail scanner fetching the link
            // three times does not start three journeys.
            $this->triggers->fire('email_clicked', (int) $resolved['contact_id'], [
                'campaign_id' => $resolved['campaign_id'] ?? 0,
                'reference'   => (string) $resolved['url'],
            ]);
        });

        // 302 rather than 301: a permanent redirect would be cached by the
        // browser and every later click on that link would never reach us.
        return Response::redirect($resolved['url'], 302)
            ->withHeader('Cache-Control', 'no-store, private')
            // The destination is the customer's own site; it has no business
            // learning which of our tracking URLs the recipient came through.
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * Bind the tenant from the verified token, run the callback, then put back
     * whatever was bound before.
     *
     * Recording is best-effort: a tracking failure must never cost the recipient
     * their click.
     *
     * @param callable():void $callback
     */
    private function withTenant(int $organisationId, callable $callback): void
    {
        $organisation = $this->organisations->findById($organisationId);

        if ($organisation === null) {
            return;
        }

        $captured = $this->tenant->capture();

        $this->tenant->clear();
        $this->tenant->bind($organisationId, null, $organisation);

        try {
            $callback();
        } catch (\Throwable $e) {
            $this->logger->warning('Could not record a tracking event', ['error' => $e->getMessage()]);
        } finally {
            $this->tenant->restore($captured);
        }
    }

    private function pixel(): Response
    {
        return Response::make((string) base64_decode(self::PIXEL, true), 200)
            ->withHeader('Content-Type', 'image/gif')
            // Caching the pixel would hide every open after the first.
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('Content-Security-Policy', "default-src 'none'");
    }
}
