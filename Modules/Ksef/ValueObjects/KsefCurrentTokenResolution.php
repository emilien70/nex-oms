<?php

namespace Modules\Ksef\ValueObjects;

final readonly class KsefCurrentTokenResolution
{
    public function __construct(
        public ?KsefCurrentTokenMetadata $token,
        public ?string $systemWarning,
    ) {}

    public function isResolved(): bool
    {
        return $this->token !== null;
    }
}
