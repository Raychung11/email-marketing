<?php

declare(strict_types=1);

namespace App\Core;

final class ValidationException extends HttpException
{
    /** @param array<string,array<int,string>> $errors */
    public function __construct(private readonly array $errors, string $message = 'The submitted data is invalid.')
    {
        parent::__construct(422, $message, 'VALIDATION_FAILED');
    }

    /** @return array<string,array<int,string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,string> */
    public function firstErrors(): array
    {
        return array_map(static fn (array $messages): string => $messages[0] ?? '', $this->errors);
    }
}
