<?php

declare(strict_types=1);

use App\Core\Clock;
use App\Core\Config;
use App\Core\Container;
use App\Database\Connection;

/**
 * Seeds the versioned compliance rule set from config/compliance.php.
 *
 * Existing versions are never modified — that is the point of versioning. A rule
 * change is a new row with a later `version` and an `effective_from`, so a send
 * made last year can still be explained with the rules that were in force then.
 */
return static function (Container $container): void {
    /** @var Connection $connection */
    $connection = $container->make(Connection::class);
    /** @var Config $config */
    $config = $container->make(Config::class);
    /** @var Clock $clock */
    $clock = $container->make(Clock::class);

    $now = $clock->nowString();

    /** @var array<string,array<string,mixed>> $countries */
    $countries = $config->get('compliance.countries', []);

    foreach ($countries as $country => $rule) {
        $ruleCode = (string) ($rule['rule_code'] ?? 'DEFAULT_MARKETING_REQUIREMENTS');
        $version  = (int) ($rule['version'] ?? 1);

        $exists = $connection->table('compliance_rules')
            ->whereNull('organisation_id')
            ->where('country', '=', $country)
            ->where('rule_code', '=', $ruleCode)
            ->where('version', '=', $version)
            ->exists();

        if ($exists) {
            continue;
        }

        $connection->table('compliance_rules')->insert([
            'organisation_id'    => null,
            'country'            => $country,
            'rule_code'          => $ruleCode,
            'version'            => $version,
            // Backdated so that rules are in force for any historical record the
            // service is asked to explain.
            'effective_from'     => '2000-01-01',
            'effective_until'    => null,
            'configuration_json' => json_encode($rule, JSON_UNESCAPED_SLASHES),
            'description'        => (string) ($rule['label'] ?? $country) . ' marketing email requirements',
            'is_active'          => 1,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }
};
