<?php

namespace App\Exceptions;

use RuntimeException;

final class GusRegonException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        string $message,
        public readonly int $httpStatus = 502,
    ) {
        parent::__construct($message);
    }
}
