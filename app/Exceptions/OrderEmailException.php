<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class OrderEmailException extends RuntimeException
{
    public function __construct(
        private readonly string $safeCode,
        string $safeMessage,
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }

    public function safeCode(): string
    {
        return $this->safeCode;
    }
}
