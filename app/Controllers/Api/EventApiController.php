<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\AttributionService;
use App\Services\EventTrackingService;

/**
 * /api/v1/events and /api/v1/conversions.
 *
 * The two endpoints a customer's website and till system talk to. Both are
 * authenticated by an API key whose scope says exactly this much and no more:
 * `events:write` can post events and read nothing, which is the right level of
 * trust for a key that sits in every page's HTML.
 *
 * Conversions are idempotent on the external id. A payment gateway that retries
 * its webhook — and they all do — must not double the day's takings.
 */
final class EventApiController
{
    public function __construct(
        private readonly EventTrackingService $events,
        private readonly AttributionService $attribution,
    ) {
    }

    /** POST /api/v1/events */
    public function store(Request $request): Response
    {
        $body = $request->json();

        // A batch, because a page that fires five events should make one request
        // rather than five. Bounded, because an unbounded batch is a way to make
        // one request cost a minute of database time.
        $batch = isset($body['events']) && is_array($body['events']) ? $body['events'] : [$body];
        $batch = array_slice($batch, 0, 50);

        $recorded   = 0;
        $identified = 0;

        foreach ($batch as $event) {
            if (!is_array($event)) {
                continue;
            }

            $result = $this->events->record($event + [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            if ($result['id'] > 0) {
                $recorded++;
            }

            if ($result['identified']) {
                $identified++;
            }
        }

        return Response::json(['recorded' => $recorded, 'identified' => $identified], 202);
    }

    /** POST /api/v1/conversions */
    public function storeConversion(Request $request): Response
    {
        $body = $request->json();

        $result = $this->attribution->record($body);

        return Response::json([
            'id'          => $result['id'],
            'duplicate'   => $result['duplicate'],
            // Returned so the caller can see what we credited it to, rather than
            // having to guess from a report later.
            'attribution' => $result['attribution'],
        ], $result['duplicate'] ? 200 : 201);
    }

    /** GET /api/v1/events/known — what the platform understands. */
    public function known(Request $request): Response
    {
        return Response::json(['events' => $this->events->knownEvents()]);
    }
}
