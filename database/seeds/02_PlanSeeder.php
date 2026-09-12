<?php

declare(strict_types=1);

use App\Core\Clock;
use App\Core\Config;
use App\Core\Container;
use App\Database\Connection;

/** Seeds the plan catalogue from config/plans.php. Idempotent. */
return static function (Container $container): void {
    /** @var Connection $connection */
    $connection = $container->make(Connection::class);
    /** @var Config $config */
    $config = $container->make(Config::class);
    /** @var Clock $clock */
    $clock = $container->make(Clock::class);

    $now = $clock->nowString();

    /** @var array<string,array<string,mixed>> $plans */
    $plans = $config->get('plans.plans', []);
    $order = 0;

    foreach ($plans as $key => $plan) {
        $payload = [
            'name'        => (string) $plan['name'],
            'description' => (string) $plan['description'],
            'prices'      => json_encode($plan['price'], JSON_UNESCAPED_SLASHES),
            'interval'    => (string) $plan['interval'],
            'limits'      => json_encode($plan['limits'], JSON_UNESCAPED_SLASHES),
            'features'    => json_encode($plan['features'], JSON_UNESCAPED_SLASHES),
            'sort_order'  => $order++,
            'is_public'   => 1,
            'updated_at'  => $now,
        ];

        $existing = $connection->table('plans')->where('key', '=', $key)->first();

        if ($existing !== null) {
            $connection->table('plans')->where('id', '=', (int) $existing['id'])->update($payload);

            continue;
        }

        $connection->table('plans')->insert(array_merge($payload, [
            'key'        => $key,
            'created_at' => $now,
        ]));
    }
};
