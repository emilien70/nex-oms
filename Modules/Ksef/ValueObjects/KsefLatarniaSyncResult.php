<?php

namespace Modules\Ksef\ValueObjects;

final readonly class KsefLatarniaSyncResult
{
    public function __construct(
        public bool $statusSuccess,
        public bool $messagesSuccess,
        public ?string $statusError,
        public ?string $messagesError,
    ) {}
}
