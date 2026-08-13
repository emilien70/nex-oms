<?php

namespace Modules\Ksef\ValueObjects;

final readonly class KsefApiResponse
{
    public function __construct(
        public array $data,
        public ?string $systemWarning,
    ) {}
}
