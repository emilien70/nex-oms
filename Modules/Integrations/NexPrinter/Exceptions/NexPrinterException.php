<?php

namespace Modules\Integrations\NexPrinter\Exceptions;

use RuntimeException;
use Throwable;

class NexPrinterException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
