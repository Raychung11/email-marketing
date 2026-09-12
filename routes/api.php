<?php

declare(strict_types=1);

use App\Controllers\Api\ContactApiController;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AuthenticateApiKey;

/*
 * REST API, /api/v1.
 *
 * Authenticated by a hashed API key, scoped per endpoint, rate limited per key.
 * The tenant is derived from the key — an organisation_id in a payload is ignored.
 *
 * CSRF does not apply here (see VerifyCsrfToken): there is no cookie to ride.
 */

return static function (Router $router): void {
    $router->group(['prefix' => '/api/v1'], static function (Router $router): void {
        // Unauthenticated: lets an integrator confirm the base URL is right.
        $router->get('/ping', static fn (): Response => Response::json([
            'ok'      => true,
            'version' => 'v1',
            'time'    => gmdate('c'),
        ]));

        $router->get('/contacts', ContactApiController::class . '@index', [
            AuthenticateApiKey::class . ':contacts:read',
        ]);
        $router->post('/contacts', ContactApiController::class . '@store', [
            AuthenticateApiKey::class . ':contacts:write',
        ]);
        $router->get('/contacts/{uuid}', ContactApiController::class . '@show', [
            AuthenticateApiKey::class . ':contacts:read',
        ]);
        $router->patch('/contacts/{uuid}', ContactApiController::class . '@update', [
            AuthenticateApiKey::class . ':contacts:write',
        ]);
        $router->delete('/contacts/{uuid}', ContactApiController::class . '@destroy', [
            AuthenticateApiKey::class . ':contacts:write',
        ]);

        // Consent can be reported by an integration, with evidence. It can never
        // be cleared silently: a withdrawal is appended like any other record.
        $router->post('/contacts/{uuid}/consent', ContactApiController::class . '@recordConsent', [
            AuthenticateApiKey::class . ':contacts:write',
        ]);
    });
};
