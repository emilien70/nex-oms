<?php

namespace Modules\Integrations\DPD\Exceptions;

use RuntimeException;

class DpdApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?array $responsePayload = null,
    ) {
        parent::__construct($message, $statusCode ?? 0);
    }
}
