<?php

namespace App\Exceptions;

use RuntimeException;

class ValidationException extends RuntimeException
{
    protected array $errors;

    public function __construct(array $errors, string $message = "Validation Failed", int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}