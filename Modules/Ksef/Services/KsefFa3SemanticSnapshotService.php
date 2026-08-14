<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Ksef\Enums\KsefZeroVatClassification;

class KsefFa3SemanticSnapshotService
{
    public function __construct(
        private readonly KsefSettingsService $settings,
        private readonly KsefFa3TaxTreatmentResolver $taxTreatments,
        private readonly KsefFa3BuyerIdentityResolver $buyerIdentity,
        private readonly KsefPaymentMethodMappingService $paymentMappings,
    ) {}

    public function initialize(Invoice $invoice): void
    {
        $this->materialize($invoice, [], true);
    }

    public function refresh(Invoice $invoice): void
    {
        if (! $invoice->isInvoice()) {
            return;
        }

        $metadata = $invoice->tax_metadata_snapshot ?? [];
        $existing = is_array($metadata['ksef_tax'] ?? null) ? $metadata['ksef_tax'] : [];
        if (($existing['version'] ?? null) !== 1) {
            return;
        }

        $this->materialize($invoice, $existing, false);
    }

    /** @param array<string, mixed> $existing */
    private function materialize(Invoice $invoice, array $existing, bool $initialize): void
    {
        if (! $invoice->isInvoice()) {
            return;
        }

        $settings = $this->settings->get();
        $metadata = $invoice->tax_metadata_snapshot ?? [];
        $payment = $invoice->payment_snapshot ?? [];
        $existingByItem = collect($existing['line_treatments'] ?? [])
            ->filter(fn (mixed $treatment): bool => is_array($treatment) && is_numeric($treatment['invoice_item_id'] ?? null))
            ->keyBy(fn (array $treatment): int => (int) $treatment['invoice_item_id']);
        $zeroClassification = $settings->zero_vat_classification;
        if (! $zeroClassification instanceof KsefZeroVatClassification) {
            $zeroClassification = KsefZeroVatClassification::Wdt;
        }

        $lineTreatments = $invoice->items()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (InvoiceItem $item): array => $this->taxTreatments->resolve(
                $item,
                $zeroClassification,
                $existingByItem->get($item->getKey()),
            ))
            ->all();

        $splitPayment = is_array($existing['annotations'] ?? null)
            && is_bool($existing['annotations']['split_payment'] ?? null)
                ? $existing['annotations']['split_payment']
                : (bool) $settings->default_split_payment;

        $metadata['ksef_tax'] = [
            'version' => 1,
            'profile' => 'ordinary',
            'annotations' => [
                'cash_accounting' => false,
                'self_billing' => false,
                'reverse_charge' => false,
                'split_payment' => $splitPayment,
                'exemption' => null,
                'new_transport_mean' => false,
                'triangular_transaction' => false,
                'margin_scheme' => false,
            ],
            'line_treatments' => $lineTreatments,
        ];
        if ($initialize && ! array_key_exists('ksef_document', $metadata)) {
            $metadata['ksef_document'] = [
                'version' => 1,
                'options' => [
                    'include_recipient_data' => (bool) $settings->include_recipient_data,
                    'include_buyer_contact_data' => (bool) $settings->include_buyer_contact_data,
                    'include_additional_information' => (bool) $settings->include_additional_information,
                    'include_order_reference' => (bool) $settings->include_order_reference,
                    'include_bank_account' => (bool) $settings->include_bank_account,
                    'include_gtu' => (bool) $settings->include_gtu,
                ],
            ];
        }
        if ($initialize) {
            $payment['ksef_payment'] = $this->paymentMappings->resolve(
                is_string($payment['effective_payment_method'] ?? null)
                    ? $payment['effective_payment_method']
                    : null,
                ($payment['cash_on_delivery'] ?? false) === true,
            );
        }
        $buyer = $invoice->buyer_snapshot ?? [];
        if ($initialize
            || (data_get($buyer, 'tax_identity.version') === 1
                && data_get($buyer, 'subject_flags.version') === 1)) {
            $buyer = $this->withBuyerSemantics($buyer);
        }

        if ($metadata !== ($invoice->tax_metadata_snapshot ?? [])
            || $buyer !== ($invoice->buyer_snapshot ?? [])
            || $payment !== ($invoice->payment_snapshot ?? [])) {
            $invoice->forceFill([
                'tax_metadata_snapshot' => $metadata,
                'buyer_snapshot' => $buyer,
                'payment_snapshot' => $payment,
            ])->save();
        }
    }

    /** @param array<string, mixed> $buyer
     * @return array<string, mixed>
     */
    public function withBuyerSemantics(array $buyer): array
    {
        return $this->buyerIdentity->withSemantics($buyer);
    }
}
