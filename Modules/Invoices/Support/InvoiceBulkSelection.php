<?php

namespace Modules\Invoices\Support;

use JsonException;
use stdClass;

final class InvoiceBulkSelection
{
    public const MAX_DOCUMENTS = 1000;

    /** @return array<int, mixed>|null */
    public static function decodeIds(mixed $selection): ?array
    {
        $decoded = self::decode($selection);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{invoice_ids: array<int, mixed>, lock_versions: array<int|string, mixed>}|null
     */
    public static function decodeLockVersions(mixed $selection): ?array
    {
        $decoded = self::decode($selection);

        if (! $decoded instanceof stdClass) {
            return null;
        }

        $invoiceIds = [];
        $lockVersions = [];

        foreach (get_object_vars($decoded) as $rawInvoiceId => $lockVersion) {
            $invoiceId = self::normalizeObjectKey($rawInvoiceId);
            $invoiceIds[] = $invoiceId;
            $lockVersions[$invoiceId] = $lockVersion;
        }

        return [
            'invoice_ids' => $invoiceIds,
            'lock_versions' => $lockVersions,
        ];
    }

    private static function decode(mixed $selection): array|stdClass|null
    {
        if (! is_string($selection) || trim($selection) === '') {
            return null;
        }

        try {
            $decoded = json_decode($selection, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) || $decoded instanceof stdClass ? $decoded : null;
    }

    private static function normalizeObjectKey(string $invoiceId): int|string
    {
        if (! ctype_digit($invoiceId)) {
            return $invoiceId;
        }

        $normalized = (int) $invoiceId;

        return (string) $normalized === $invoiceId ? $normalized : $invoiceId;
    }
}
