<?php

namespace Modules\Integrations\PayNow\Exceptions;

use RuntimeException;

class PayNowWebhookException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
    ) {
        parent::__construct($message);
    }
}
