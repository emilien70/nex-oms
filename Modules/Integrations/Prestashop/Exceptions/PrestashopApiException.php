<?php

namespace Modules\Integrations\Prestashop\Exceptions;

use RuntimeException;

class PrestashopApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
    ) {
        parent::__construct($message);
    }
}
