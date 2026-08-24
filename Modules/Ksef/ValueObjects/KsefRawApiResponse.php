<?php

namespace Modules\Ksef\ValueObjects;

final readonly class KsefRawApiResponse
{
    public function __construct(
        public string $body,
        public ?string $contentHash,
        public ?string $systemWarning,
    ) {}
}
