<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;

/**
 * Generic per-IP throttle: `Throttle::class . ':60,60'` = 60 requests / 60s.
 *
 * Applied to public endpoints (unsubscribe, tracking, form posts) so they cannot
 * be used for enumeration or as an amplification target.
 */
final class Throttle implements Middleware
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        [$maxAttempts, $decaySeconds] = array_pad(explode(',', (string) $argument), 2, null);

        $max   = (int) ($maxAttempts ?? 60);
        $decay = (int) ($decaySeconds ?? 60);

        $key = 'throttle:' . hash('sha256', $request->path() . '|' . $request->ip());

        if ($this->limiter->tooManyAttempts($key, $max)) {
            throw HttpException::tooManyRequests();
        }

        $this->limiter->hit($key, $decay);

        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $max)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $max - $this->limiter->attempts($key)));
    }
}
