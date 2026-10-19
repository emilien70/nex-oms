<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\Prestashop\Models\PrestashopOrderPayment;

class InvoicePaymentIdentifierResolver
{
    public function resolve(Order $order): ?string
    {
        $prestashopIdentifiers = PrestashopOrderPayment::query()
            ->whereHas('orderLink', fn ($query) => $query->where('order_id', $order->getKey()))
            ->whereIn('accounting_status', [
                PrestashopOrderPayment::STATUS_ACCOUNTED,
                PrestashopOrderPayment::STATUS_MIRRORED,
            ])
            ->whereNotNull('transaction_id')
            ->where('transaction_id', '<>', '')
            ->orderBy('paid_at')
            ->orderBy('id')
            ->pluck('transaction_id')
            ->map(fn (mixed $identifier): string => trim((string) $identifier))
            ->filter()
            ->unique()
            ->values();

        if ($prestashopIdentifiers->isNotEmpty()) {
            return $prestashopIdentifiers->implode(', ');
        }

        $payNowIdentifiers = $order->payNowPayments()
            ->where('status', PayNowPaymentStatus::Confirmed->value)
            ->whereNotNull('accounted_at')
            ->where('needs_review', false)
            ->whereNotNull('provider_payment_id')
            ->where('provider_payment_id', '<>', '')
            ->orderBy('accounted_at')
            ->orderBy('id')
            ->pluck('provider_payment_id')
            ->map(fn (mixed $identifier): string => trim((string) $identifier))
            ->filter()
            ->unique()
            ->values();

        return $payNowIdentifiers->isEmpty()
            ? null
            : $payNowIdentifiers->implode(', ');
    }
}
