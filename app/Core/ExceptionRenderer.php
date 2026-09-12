<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Turns an exception into a response.
 *
 * A stack trace never reaches an end user when APP_DEBUG is false. What they do
 * get is a correlation id that ties their report to a log line, which is more
 * useful to them and far less useful to an attacker.
 */
final class ExceptionRenderer
{
    public function __construct(
        private readonly View $view,
        private readonly Logger $logger,
        private readonly Config $config,
        private readonly Session $session,
    ) {
    }

    public function render(Request $request, Throwable $e): Response
    {
        $status  = $e instanceof HttpException ? $e->statusCode() : 500;
        $debug   = (bool) $this->config->get('app.debug', false);
        $traceId = $this->logger->correlationId();

        if ($status >= 500) {
            $this->logger->error($e->getMessage(), [
                'exception' => $e::class,
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'path'      => $request->path(),
                'method'    => $request->method(),
                'trace'     => $debug ? $e->getTraceAsString() : null,
            ]);
        } else {
            $this->logger->info('Request rejected: ' . $e->getMessage(), [
                'status' => $status,
                'path'   => $request->path(),
            ]);
        }

        if ($e instanceof ValidationException) {
            return $this->renderValidation($request, $e);
        }

        if ($request->expectsJson()) {
            return Response::json([
                'error' => [
                    'code'    => $e instanceof HttpException && $e->errorCode() !== ''
                        ? $e->errorCode()
                        : ($status >= 500 ? 'SERVER_ERROR' : 'REQUEST_ERROR'),
                    'message' => $this->safeMessage($e, $status, $debug),
                    'trace_id' => $traceId,
                ],
            ], $status);
        }

        try {
            return Response::html($this->view->render('errors.error', [
                'status'   => $status,
                'title'    => $this->titleFor($status),
                'message'  => $this->safeMessage($e, $status, $debug),
                'traceId'  => $traceId,
                'debug'    => $debug,
                'exception' => $debug ? $e : null,
            ]), $status);
        } catch (Throwable) {
            // The error page itself failed; fall back to plain text rather than
            // recursing.
            return Response::text(
                $this->titleFor($status) . ' (reference: ' . $traceId . ')',
                $status
            );
        }
    }

    private function renderValidation(Request $request, ValidationException $e): Response
    {
        if ($request->expectsJson()) {
            return Response::json([
                'error' => [
                    'code'    => 'VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                    'errors'  => $e->errors(),
                ],
            ], 422);
        }

        // Non-JSON: bounce back with the errors and the submitted values flashed.
        $this->session->flash('errors', $e->errors());
        $this->session->flash('old', array_diff_key($request->all(), array_flip(['_token', 'password', 'password_confirmation'])));
        $this->session->flash('error', $e->getMessage());

        $referer = $request->header('Referer');
        $target  = '/';

        if (is_string($referer) && $referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);

            // Only ever redirect to a local path.
            if (is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//')) {
                $target = $path;
            }
        }

        return Response::redirect($target);
    }

    private function safeMessage(Throwable $e, int $status, bool $debug): string
    {
        if ($status < 500) {
            return $e->getMessage();
        }

        return $debug
            ? $e->getMessage()
            : 'Something went wrong on our side. The problem has been logged.';
    }

    private function titleFor(int $status): string
    {
        return match ($status) {
            401     => 'Sign in required',
            403     => 'Not permitted',
            404     => 'Not found',
            405     => 'Method not allowed',
            419     => 'Session expired',
            422     => 'Could not save',
            429     => 'Too many requests',
            503     => 'Temporarily unavailable',
            default => 'Something went wrong',
        };
    }
}
