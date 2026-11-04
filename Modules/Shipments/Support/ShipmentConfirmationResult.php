<?php

namespace Modules\Shipments\Support;

final class ShipmentConfirmationResult
{
    public function __construct(
        public readonly bool $completed,
        public readonly ?int $retryAfter = null,
    ) {}
}
