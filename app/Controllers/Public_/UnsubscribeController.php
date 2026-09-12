<?php

declare(strict_types=1);

namespace App\Controllers\Public_;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\UnsubscribeService;

/**
 * The public unsubscribe and preference pages.
 *
 * No authentication: the recipient is not a user. Authorisation comes from the
 * HMAC-signed token, which carries the contact's UUID rather than an id, so these
 * URLs cannot be enumerated or edited into someone else's unsubscribe.
 *
 * A one-click POST is supported for RFC 8058 List-Unsubscribe-Post.
 */
final class UnsubscribeController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly UnsubscribeService $unsubscribe,
    ) {
        parent::__construct($view, $session, $config);
    }

    /**
     * GET /unsubscribe/{token}
     *
     * Shows a confirmation page rather than unsubscribing immediately, because
     * mail clients and security scanners routinely pre-fetch links and a GET that
     * changes state would unsubscribe people who never clicked.
     */
    public function show(Request $request): Response
    {
        $resolved = $this->unsubscribe->resolve((string) $request->route('token', ''));

        if ($resolved === null) {
            return $this->renderPublic('public.unsubscribe_invalid', [], 404);
        }

        return $this->renderPublic('public.unsubscribe_confirm', [
            'organisation' => $resolved['organisation'],
            'email'        => $resolved['contact']['email'],
            'token'        => (string) $request->route('token', ''),
        ]);
    }

    /** POST /unsubscribe/{token} — the actual unsubscribe. */
    public function unsubscribe(Request $request): Response
    {
        $token    = (string) $request->route('token', '');
        $resolved = $this->unsubscribe->resolve($token);

        if ($resolved === null) {
            return $this->renderPublic('public.unsubscribe_invalid', [], 404);
        }

        $this->unsubscribe->unsubscribeAll(
            $resolved,
            $request->ip(),
            $request->userAgent(),
            $request->bool('one_click') ? 'one_click' : 'link'
        );

        // RFC 8058 one-click clients expect a bare 200, not a page.
        if ($request->bool('one_click') || $request->string('List-Unsubscribe') === 'One-Click') {
            return Response::text('Unsubscribed', 200);
        }

        return $this->renderPublic('public.unsubscribe_done', [
            'organisation'    => $resolved['organisation'],
            'email'           => $resolved['contact']['email'],
            'preferencesUrl'  => $this->unsubscribe->preferencesUrlFor(
                (int) $resolved['organisation']['id'],
                (string) $resolved['contact']['uuid']
            ),
        ]);
    }

    /** GET /preferences/{token} — the preference centre. */
    public function preferences(Request $request): Response
    {
        $token    = (string) $request->route('token', '');
        $resolved = $this->unsubscribe->resolve($token);

        if ($resolved === null) {
            return $this->renderPublic('public.unsubscribe_invalid', [], 404);
        }

        return $this->renderPublic('public.preferences', [
            'organisation' => $resolved['organisation'],
            'email'        => $resolved['contact']['email'],
            'token'        => $token,
            'topics'       => $this->config->get('compliance.preference_topics', []),
            'current'      => $this->unsubscribe->currentPreferences($resolved),
        ]);
    }

    /** POST /preferences/{token} */
    public function updatePreferences(Request $request): Response
    {
        $token    = (string) $request->route('token', '');
        $resolved = $this->unsubscribe->resolve($token);

        if ($resolved === null) {
            return $this->renderPublic('public.unsubscribe_invalid', [], 404);
        }

        $topics = $request->array('topics');

        // "All marketing" unticked means unsubscribe from everything, whatever
        // else is ticked.
        if (!in_array('all', $topics, true)) {
            $topics = [];
        } else {
            $topics = array_values(array_filter($topics, static fn (string $t): bool => $t !== 'all'));

            if ($topics === []) {
                // "All" ticked with no specific topics means keep everything.
                $topics = array_values(array_filter(
                    array_keys((array) $this->config->get('compliance.preference_topics', [])),
                    static fn (string $t): bool => $t !== 'all'
                ));
            }
        }

        $this->unsubscribe->updatePreferences($resolved, $topics, $request->ip(), $request->userAgent());

        if ($topics === []) {
            return $this->renderPublic('public.unsubscribe_done', [
                'organisation'   => $resolved['organisation'],
                'email'          => $resolved['contact']['email'],
                'preferencesUrl' => null,
            ]);
        }

        return $this->renderPublic('public.preferences_saved', [
            'organisation' => $resolved['organisation'],
            'email'        => $resolved['contact']['email'],
        ]);
    }

    /**
     * These pages render outside a tenant and outside the app shell — the visitor
     * is a member of the public, so no navigation, no organisation switcher and
     * nothing that assumes a session.
     *
     * @param array<string,mixed> $data
     */
    private function renderPublic(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data), $status)
            // Never let an unsubscribe page be cached or indexed.
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
