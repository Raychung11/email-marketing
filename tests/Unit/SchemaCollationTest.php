<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Grammars\MySqlGrammar;
use App\Database\SchemaDumper;
use InvalidArgumentException;
use Tests\Support\TestCase;

/**
 * The schema builder must stamp the *configured* collation on every table.
 *
 * This is not a style preference. MySQL 8 and MariaDB do not share collation
 * names: utf8mb4_0900_ai_ci has never existed in MariaDB, which is what nearly
 * all shared hosting runs. A hardcoded collation means the very first
 * CREATE TABLE of a deployment fails with "Unknown collation" and the product
 * cannot be installed at all.
 */
final class SchemaCollationTest extends TestCase
{
    private function dump(string $collation): string
    {
        return (new SchemaDumper(
            dirname(__DIR__, 2) . '/database/migrations',
            'utf8mb4',
            $collation
        ))->toSql('mysql');
    }

    public function testEveryTableIsCreatedWithTheConfiguredCollation(): void
    {
        $sql = $this->dump('utf8mb4_unicode_ci');

        $creates = preg_match_all('/CREATE TABLE IF NOT EXISTS/', $sql);
        $stamped = preg_match_all('/COLLATE=utf8mb4_unicode_ci/', $sql);

        $this->assertTrue($creates > 50, 'The dump really did render the whole schema');
        $this->assertSame(
            $creates,
            $stamped,
            'Every CREATE TABLE carries the configured collation, not just some of them'
        );
    }

    public function testTheMySql8CollationIsNotHardcoded(): void
    {
        // The regression: DB_COLLATION was read into config and then thrown away,
        // because Schema constructed MySqlGrammar with no arguments.
        $this->assertNotContainsString(
            'utf8mb4_0900_ai_ci',
            $this->dump('utf8mb4_unicode_ci'),
            'A MariaDB-safe collation must not leave MySQL-8-only names in the DDL'
        );
    }

    public function testMySql8CollationIsStillAvailableWhenItIsAskedFor(): void
    {
        $this->assertContainsString(
            'COLLATE=utf8mb4_0900_ai_ci',
            $this->dump('utf8mb4_0900_ai_ci'),
            'Choosing the better MySQL 8 collation is still supported, it is just no longer assumed'
        );
    }

    public function testACollationCannotSmuggleSqlIntoTheDdl(): void
    {
        // Charset and collation cannot be bound as placeholders, so they are
        // interpolated. That makes validating them the only thing standing
        // between a mangled .env and arbitrary DDL.
        foreach (['utf8mb4_unicode_ci; DROP TABLE users', 'utf8mb4 unicode', "a'b", ''] as $bad) {
            $this->assertThrows(
                InvalidArgumentException::class,
                static fn (): MySqlGrammar => new MySqlGrammar('utf8mb4', $bad),
                'A collation that is not a bare identifier is refused: ' . $bad
            );
        }
    }
}
