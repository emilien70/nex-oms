<?php

namespace Modules\Integrations\PayNow\Exceptions;

use RuntimeException;

class PayNowApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $safeCode = null,
    ) {
        parent::__construct($message);
    }
}
