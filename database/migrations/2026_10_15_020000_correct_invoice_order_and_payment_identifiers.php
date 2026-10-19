<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REPAIR_SOURCE = 'provider_transaction_repair_v1';

    public function up(): void
    {
        DB::table('invoices')
            ->whereIn('document_type', ['invoice', 'proforma'])
            ->whereNotNull('order_id')
            ->orderBy('id')
            ->chunkById(100, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $order = $this->snapshot($invoice->order_snapshot);
                    $payment = $this->snapshot($invoice->payment_snapshot);
                    $externalOrderId = trim((string) ($order['external_id'] ?? ''));
                    $currentIdentifier = trim((string) ($payment['payment_identifier'] ?? ''));

                    if ($externalOrderId === '' || $currentIdentifier !== $externalOrderId) {
                        continue;
                    }

                    $cutoff = $invoice->issued_at ?: $invoice->created_at;
                    $identifiers = $this->providerIdentifiers((int) $invoice->order_id, $cutoff);
                    $payment['payment_identifier'] = $identifiers === []
                        ? null
                        : implode(', ', $identifiers);
                    $payment['payment_identifier_source'] = self::REPAIR_SOURCE;

                    DB::table('invoices')
                        ->where('id', $invoice->id)
                        ->update([
                            'payment_snapshot' => json_encode(
                                $payment,
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                            ),
                        ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('invoices')
            ->whereIn('document_type', ['invoice', 'proforma'])
            ->orderBy('id')
            ->chunkById(100, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $order = $this->snapshot($invoice->order_snapshot);
                    $payment = $this->snapshot($invoice->payment_snapshot);

                    if (($payment['payment_identifier_source'] ?? null) !== self::REPAIR_SOURCE) {
                        continue;
                    }

                    $payment['payment_identifier'] = $order['external_id'] ?? null;
                    unset($payment['payment_identifier_source']);

                    DB::table('invoices')
                        ->where('id', $invoice->id)
                        ->update([
                            'payment_snapshot' => json_encode(
                                $payment,
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                            ),
                        ]);
                }
            });
    }

    /** @return array<int, string> */
    private function providerIdentifiers(int $orderId, mixed $cutoff): array
    {
        $prestashop = Schema::hasTable('prestashop_order_payments')
            ? DB::table('prestashop_order_payments as payment')
                ->join('prestashop_order_links as link', 'link.id', '=', 'payment.prestashop_order_link_id')
                ->where('link.order_id', $orderId)
                ->whereIn('payment.accounting_status', ['accounted', 'mirrored'])
                ->whereNotNull('payment.transaction_id')
                ->where('payment.transaction_id', '<>', '')
                ->when($cutoff, fn ($query) => $query->where('payment.paid_at', '<=', $cutoff))
                ->orderBy('payment.paid_at')
                ->orderBy('payment.id')
                ->pluck('payment.transaction_id')
                ->map(fn (mixed $identifier): string => trim((string) $identifier))
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];

        if ($prestashop !== []) {
            return $prestashop;
        }

        return Schema::hasTable('paynow_payments')
            ? DB::table('paynow_payments')
                ->where('order_id', $orderId)
                ->where('status', 'CONFIRMED')
                ->whereNotNull('accounted_at')
                ->where('needs_review', false)
                ->whereNotNull('provider_payment_id')
                ->where('provider_payment_id', '<>', '')
                ->when($cutoff, fn ($query) => $query->where('accounted_at', '<=', $cutoff))
                ->orderBy('accounted_at')
                ->orderBy('id')
                ->pluck('provider_payment_id')
                ->map(fn (mixed $identifier): string => trim((string) $identifier))
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];
    }

    /** @return array<string, mixed> */
    private function snapshot(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
