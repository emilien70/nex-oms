<?php

namespace Modules\Integrations\InPost\Exceptions;

use RuntimeException;

class InPostApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?array $responsePayload = null,
    ) {
        parent::__construct($message);
    }
}
