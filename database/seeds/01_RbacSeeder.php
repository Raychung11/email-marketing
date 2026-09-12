<?php

declare(strict_types=1);

use App\Core\Clock;
use App\Core\Config;
use App\Core\Container;
use App\Database\Connection;

/**
 * Seeds roles, permissions and the role→permission matrix from config/rbac.php.
 *
 * Idempotent: safe to re-run after adding a permission, which is what makes
 * `composer install && migrate && db:seed` a valid deploy step.
 */
return static function (Container $container): void {
    /** @var Connection $connection */
    $connection = $container->make(Connection::class);
    /** @var Config $config */
    $config = $container->make(Config::class);
    /** @var Clock $clock */
    $clock = $container->make(Clock::class);

    $now = $clock->nowString();

    // --- Permissions --------------------------------------------------------
    /** @var array<string,string> $permissions */
    $permissions   = $config->get('rbac.permissions', []);
    $permissionIds = [];

    foreach ($permissions as $key => $name) {
        $existing = $connection->table('permissions')->where('key', '=', $key)->first();

        if ($existing !== null) {
            $permissionIds[$key] = (int) $existing['id'];

            continue;
        }

        $permissionIds[$key] = $connection->table('permissions')->insert([
            'key'        => $key,
            'name'       => $name,
            'group_key'  => explode('.', $key)[0],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // --- Roles --------------------------------------------------------------
    /** @var array<string,string> $roles */
    $roles = $config->get('rbac.roles', []);

    // Rank orders the team screen and decides who outranks whom in the UI; it is
    // never used for an authorisation decision.
    $ranks = [
        'OWNER'             => 100,
        'ADMIN'             => 90,
        'MARKETING_MANAGER' => 80,
        'APPROVER'          => 70,
        'MARKETER'          => 60,
        'SALES'             => 50,
        'ANALYST'           => 40,
        'VIEWER'            => 10,
    ];

    $roleIds = [];

    foreach ($roles as $key => $name) {
        $existing = $connection->table('roles')
            ->where('key', '=', $key)
            ->whereNull('organisation_id')
            ->first();

        if ($existing !== null) {
            $roleIds[$key] = (int) $existing['id'];

            continue;
        }

        $roleIds[$key] = $connection->table('roles')->insert([
            'organisation_id' => null,
            'key'             => $key,
            'name'            => $name,
            'is_system'       => 1,
            'rank'            => $ranks[$key] ?? 0,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    // --- Role → permission matrix -------------------------------------------
    /** @var array<string,array<int,string>> $matrix */
    $matrix = $config->get('rbac.role_permissions', []);

    foreach ($matrix as $roleKey => $granted) {
        if (!isset($roleIds[$roleKey])) {
            continue;
        }

        $roleId = $roleIds[$roleKey];

        $keys = in_array('*', $granted, true) ? array_keys($permissions) : $granted;

        foreach ($keys as $permissionKey) {
            if (!isset($permissionIds[$permissionKey])) {
                continue;
            }

            $exists = $connection->table('role_permissions')
                ->where('role_id', '=', $roleId)
                ->where('permission_id', '=', $permissionIds[$permissionKey])
                ->exists();

            if (!$exists) {
                $connection->table('role_permissions')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => $permissionIds[$permissionKey],
                ]);
            }
        }
    }
};
