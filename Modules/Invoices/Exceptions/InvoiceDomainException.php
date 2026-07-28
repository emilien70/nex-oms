<?php

namespace Modules\Invoices\Exceptions;

use DomainException;
use Throwable;

class InvoiceDomainException extends DomainException
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $metadata = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
