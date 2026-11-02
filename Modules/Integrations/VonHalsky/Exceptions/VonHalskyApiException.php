<?php

namespace Modules\Integrations\VonHalsky\Exceptions;

use RuntimeException;

class VonHalskyApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
