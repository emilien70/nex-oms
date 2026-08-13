<?php

namespace Modules\Ksef\Exceptions;

use RuntimeException;

class KsefApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $safeCode,
        public readonly ?int $httpStatus = null,
        public readonly ?string $reasonCode = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $systemWarning = null,
    ) {
        parent::__construct($message);
    }

    public function isRefreshAuthorizationFailure(): bool
    {
        return in_array($this->httpStatus, [401, 403, 410], true);
    }

    public function isCredentialOrContextFailure(): bool
    {
        return in_array($this->safeCode, [
            'auth_status_415',
            'auth_status_425',
            'auth_status_450',
            'auth_status_480',
        ], true);
    }
}
