<?php

namespace Modules\Integrations\Allegro\Exceptions;

use RuntimeException;

class AllegroApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly array $responsePayload = [],
    ) {
        parent::__construct($message, $statusCode ?? 0);
    }
}
