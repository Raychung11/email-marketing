<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;

/**
 * Shared controller plumbing.
 *
 * Controllers validate input, call exactly one service, and render. Business
 * rules live in services; SQL lives in repositories. Nothing here reaches a
 * database.
 */
abstract class Controller
{
    public function __construct(
        protected readonly View $view,
        protected readonly Session $session,
        protected readonly Config $config,
    ) {
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = []): Response
    {
        // Errors and old input flashed by a failed validation round-trip.
        $flash = $this->session->pullFlash();

        return Response::html($this->view->render($template, array_merge([
            'errors'  => $flash['errors'] ?? [],
            'old'     => $flash['old'] ?? [],
            'success' => $flash['success'] ?? null,
            'error'   => $flash['error'] ?? null,
            'warning' => $flash['warning'] ?? null,
        ], $data)));
    }

    /**
     * @param array<string,string> $rules
     * @param array<string,string> $messages
     * @return array<string,mixed>
     */
    protected function validate(Request $request, array $rules, array $messages = []): array
    {
        return (new Validator($request->all(), $rules, $messages))->validate();
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    protected function back(Request $request, string $fallback = '/'): Response
    {
        $referer = $request->header('Referer');

        if (is_string($referer) && $referer !== '') {
            $path  = parse_url($referer, PHP_URL_PATH);
            $query = parse_url($referer, PHP_URL_QUERY);

            // Only ever redirect to a local path: an absolute Referer would make
            // this an open redirect.
            if (is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//')) {
                return Response::redirect($path . ($query !== null && $query !== '' ? '?' . $query : ''));
            }
        }

        return Response::redirect($fallback);
    }

    protected function withSuccess(string $to, string $message): Response
    {
        $this->session->flash('success', $message);

        return Response::redirect($to);
    }

    protected function withError(string $to, string $message): Response
    {
        $this->session->flash('error', $message);

        return Response::redirect($to);
    }

    /**
     * For the half-success: the thing the user asked for did happen, but
     * something downstream of it did not. Reporting these as a plain success is
     * how a customer ends up waiting on an email that was never sent.
     */
    protected function withWarning(string $to, string $message): Response
    {
        $this->session->flash('warning', $message);

        return Response::redirect($to);
    }

    protected function page(Request $request): int
    {
        return max(1, $request->int('page', 1));
    }

    protected function perPage(Request $request): int
    {
        $default = (int) $this->config->get('app.pagination.per_page', 25);
        $max     = (int) $this->config->get('app.pagination.max_per_page', 200);

        return max(1, min($request->int('per_page', $default), $max));
    }
}
