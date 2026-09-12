<?php

declare(strict_types=1);

namespace App\Database;

use App\Database\Schema\Schema;

abstract class Migration
{
    abstract public function up(Schema $schema, Connection $connection): void;

    abstract public function down(Schema $schema, Connection $connection): void;
}
