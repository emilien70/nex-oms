<?php

namespace Modules\Shipments\Support;

final class OrderReferenceFormatter
{
    private const MIN_LENGTH = 3;

    public static function format(int|string $orderId): string
    {
        return str_pad((string) $orderId, self::MIN_LENGTH, '0', STR_PAD_LEFT);
    }
}
