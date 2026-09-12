<?php

declare(strict_types=1);

/**
 * Single front controller. Nothing else in public/ is executable.
 */

use App\Core\Application;

$app = require __DIR__ . '/../bootstrap/app.php';

// Maintenance flag, set by `php cron/console.php down`. Health checks still pass
// so an orchestrator does not kill the container during a deploy.
$maintenanceFlag = __DIR__ . '/../storage/framework/maintenance.flag';
$path            = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (is_file($maintenanceFlag) && !str_starts_with((string) $path, '/health')) {
    http_response_code(503);
    header('Retry-After: 120');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><title>Back shortly</title>'
        . '<div style="font-family:system-ui;max-width:32rem;margin:15vh auto;text-align:center">'
        . '<h1 style="font-size:1.25rem">We are deploying an update</h1>'
        . '<p style="color:#555">This takes a minute or two. Queued email keeps sending.</p>'
        . '</div>';

    exit;
}

$app->run();
