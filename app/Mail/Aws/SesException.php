<?php

declare(strict_types=1);

namespace App\Mail\Aws;

use RuntimeException;

final class SesException extends RuntimeException
{
    public function __construct(string $message, public readonly string $awsCode = '')
    {
        parent::__construct($message);
    }
}
