<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class OrderEmailTransportException extends RuntimeException
{
    public function __construct(
        private readonly bool $outcomeUnknown,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Nie udało się wysłać wiadomości przez skonfigurowane konto SMTP.', 0, $previous);
    }

    public function outcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }
}
