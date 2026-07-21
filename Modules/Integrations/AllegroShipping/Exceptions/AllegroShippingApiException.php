<?php

namespace Modules\Integrations\AllegroShipping\Exceptions;

use RuntimeException;

class AllegroShippingApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly array $responsePayload = [],
    ) {
        parent::__construct($message, $statusCode ?? 0);
    }
}
