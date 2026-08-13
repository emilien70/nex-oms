<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use App\Support\CountryCatalog;
use Modules\Invoices\Enums\CorrectionIssuerSource;
use Modules\Invoices\Enums\CorrectionPaymentMethodSource;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\CorrectionSaleDateSource;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Models\InvoiceSeries;

class CorrectionViewModelFactory
{
    public function __construct(
        private readonly CorrectionSourceStateService $sourceState,
        private readonly CorrectionSeriesResolver $seriesResolver,
        private readonly AdditionalInformationRenderer $additionalInformation,
        private readonly CountryCatalog $countries,
        private readonly CorrectionSeriesSourceResolver $seriesSources,
        private readonly InvoiceTaxIdentityNormalizer $taxIdentity,
    ) {}

    /** @return array<string, mixed> */
    public function make(Invoice $sourceInvoice, ?int $seriesId = null): array
    {
        $this->sourceState->assertSourceInvoice($sourceInvoice);
        $sourceInvoice->loadMissing(['order.items', 'series.defaultCorrectionSeries']);

        $series = $this->seriesResolver->resolve($sourceInvoice, $seriesId);
        $chain = $this->sourceState->chain($sourceInvoice);
        $buyer = $this->sourceState->effectiveBuyer($sourceInvoice, false, $chain);
        $items = $this->sourceState->effectiveItems($sourceInvoice, false, $chain)
            ->map(fn (array $item): array => $this->formItem($item))
            ->values();

        $issueDate = now(config('app.timezone'))->toDateString();
        $resolvedSources = $this->seriesSources->forIssue($sourceInvoice, $series, $issueDate);

        return [
            'sourceInvoice' => $sourceInvoice,
            'effectiveSource' => $chain->effectiveSourceDocument,
            'order' => $sourceInvoice->order,
            'correctionSeries' => $this->seriesResolver->active(),
            'selectedSeries' => $series,
            'reasons' => CorrectionReason::cases(),
            'items' => $items,
            'buyer' => $buyer,
            'currentBuyer' => $this->currentBuyer($sourceInvoice->order),
            'currentOrderItems' => $this->currentOrderItems($sourceInvoice->order),
            'countries' => $this->countries->all(),
            'defaults' => $this->seriesDefaults($sourceInvoice, $series, $issueDate, $resolvedSources),
            'sourceModes' => $this->sourceModes($resolvedSources['sources']),
            'defaultChangeItems' => false,
            'defaultChangeBuyer' => false,
            'correction' => null,
            'isReadOnly' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function makeForEdit(Invoice $correction): array
    {
        $this->assertEditableCorrection($correction);
        $correction->loadMissing(['order.items', 'correctedInvoice', 'series', 'items']);

        $sourceInvoice = $correction->correctedInvoice;
        $series = $correction->series;
        if ($sourceInvoice === null || $series === null) {
            throw new InvoiceDomainException(
                'correction_edit_incomplete',
                'Nie można edytować Korekty z powodu niekompletnych powiązań dokumentu.',
            );
        }

        $this->sourceState->assertSourceInvoice($sourceInvoice);
        $chain = $this->sourceState->chain($sourceInvoice);
        $isValidChainDocument = $correction->isFinalized()
            ? $chain->contains($correction)
            : $chain->currentCorrection?->is($correction);

        if (! $isValidChainDocument) {
            throw new InvoiceDomainException(
                'correction_edit_inconsistent',
                'Nie można edytować Korekty, ponieważ jej slot lub powiązania są niespójne.',
            );
        }

        $buyerBefore = data_get($correction->order_snapshot, 'correction.buyer_before');
        if (! is_array($buyerBefore) || ! is_array($correction->buyer_snapshot)) {
            throw new InvoiceDomainException(
                'correction_edit_incomplete',
                'Nie można edytować Korekty z powodu niekompletnego snapshotu danych nabywcy.',
            );
        }

        $items = $correction->items
            ->map(fn (InvoiceItem $item): array => $this->correctionFormItem($item))
            ->values();
        $resolvedSources = $this->seriesSources->forUpdate(
            $sourceInvoice,
            $correction,
            $correction->issue_date?->toDateString() ?? '',
        );

        return [
            'sourceInvoice' => $sourceInvoice,
            'effectiveSource' => $sourceInvoice,
            'order' => $correction->order,
            'correctionSeries' => $series->newCollection([$series]),
            'selectedSeries' => $series,
            'reasons' => CorrectionReason::cases(),
            'items' => $items,
            'buyer' => $correction->buyer_snapshot,
            'currentBuyer' => $this->currentBuyer($correction->order),
            'currentOrderItems' => $this->currentOrderItems($correction->order),
            'countries' => $this->countries->all(),
            'defaults' => $this->correctionDefaults($correction, $resolvedSources),
            'sourceModes' => $this->sourceModes($resolvedSources['sources']),
            'defaultChangeItems' => $this->itemsChanged($correction),
            'defaultChangeBuyer' => $this->comparableBuyer($buyerBefore) !== $this->comparableBuyer($correction->buyer_snapshot),
            'correction' => $correction,
            'isReadOnly' => $correction->isFinalized(),
        ];
    }

    private function assertEditableCorrection(Invoice $correction): void
    {
        if ($correction->document_type !== InvoiceDocumentType::Correction
            || $correction->status !== InvoiceDocumentStatus::Issued
            || $correction->number === null) {
            throw new InvoiceDomainException(
                'correction_edit_invalid',
                'Można edytować wyłącznie wystawioną Korektę.',
            );
        }

    }

    /** @return array<string, mixed> */
    private function correctionFormItem(InvoiceItem $item): array
    {
        $snapshot = $item->correction_after_snapshot;
        if (! is_array($snapshot)) {
            throw new InvoiceDomainException(
                'correction_edit_incomplete',
                'Nie można edytować Korekty z powodu niekompletnego snapshotu pozycji.',
            );
        }

        $identity = $this->taxIdentity->normalize(
            $snapshot['vat_rate'] ?? null,
            $snapshot['vat_code'] ?? null,
        );

        return [
            'source_item_id' => $item->getKey(),
            'order_item_id' => $item->order_item_id,
            'line_type' => $this->enumValue($snapshot['line_type'] ?? 'custom'),
            'position' => (int) ($snapshot['position'] ?? 1),
            'name' => (string) ($snapshot['name'] ?? ''),
            'description' => (string) ($snapshot['description'] ?? ''),
            'unit_name' => (string) ($snapshot['unit_name'] ?? 'szt.'),
            'quantity' => $this->integerQuantity($snapshot['quantity'] ?? 0),
            'unit_price_gross' => $this->decimalForForm($snapshot['unit_price_gross'] ?? 0, 2),
            'vat_rate' => $this->vatRateForForm($identity['vat_rate']),
            'vat_code' => $identity['vat_code'],
        ];
    }

    /** @return array<string, mixed> */
    private function correctionDefaults(Invoice $correction, array $resolvedSources): array
    {
        $reason = $this->correctionReasonDefault($correction->correction_reason);

        return [
            'reason' => $reason['reason'],
            'other_reason' => $reason['other_reason'],
            'issue_date' => $correction->issue_date?->toDateString(),
            'sale_date' => $resolvedSources['sale_date'],
            'payment_method' => $resolvedSources['payment_snapshot']['effective_payment_method'] ?? null,
            'issuer_name' => $resolvedSources['issuer_snapshot']['issuer_name'] ?? null,
            'additional_information' => $correction->additional_information_text,
        ];
    }

    private function itemsChanged(Invoice $correction): bool
    {
        return $correction->items->contains(function (InvoiceItem $item): bool {
            return is_array($item->correction_before_snapshot)
                && is_array($item->correction_after_snapshot)
                && $item->correction_before_snapshot !== $item->correction_after_snapshot;
        });
    }

    /** @param array<string, mixed> $buyer
     * @return array<string, mixed>
     */
    private function comparableBuyer(array $buyer): array
    {
        return array_intersect_key($buyer, array_flip([
            'name', 'company_name', 'tax_id', 'street', 'building_number',
            'apartment_number', 'postal_code', 'city', 'country_code',
        ]));
    }

    /** @return array<string, mixed> */
    private function formItem(array $item): array
    {
        $snapshot = $item['snapshot'];
        $identity = $this->taxIdentity->normalize(
            $snapshot['vat_rate'] ?? null,
            $snapshot['vat_code'] ?? null,
        );

        return [
            'source_item_id' => $item['source_item_id'],
            'order_item_id' => $item['source_item']->order_item_id,
            'line_type' => $this->enumValue($snapshot['line_type'] ?? 'custom'),
            'position' => (int) ($snapshot['position'] ?? 1),
            'name' => (string) ($snapshot['name'] ?? ''),
            'description' => (string) ($snapshot['description'] ?? ''),
            'unit_name' => (string) ($snapshot['unit_name'] ?? 'szt.'),
            'quantity' => $this->integerQuantity($snapshot['quantity'] ?? 0),
            'unit_price_gross' => $this->decimalForForm($snapshot['unit_price_gross'] ?? 0, 2),
            'vat_rate' => $this->vatRateForForm($identity['vat_rate']),
            'vat_code' => $identity['vat_code'],
        ];
    }

    /** @return array<string, mixed> */
    private function currentBuyer(Order $order): array
    {
        $countryCode = $this->countries->normalize($order->billing_country_code);

        return [
            'name' => $order->billing_name,
            'company_name' => $order->billing_company_name,
            'tax_id' => $order->billing_tax_id,
            'street' => $order->billing_street,
            'building_number' => $order->billing_building_number,
            'apartment_number' => $order->billing_apartment_number,
            'postal_code' => $order->billing_postal_code,
            'city' => $order->billing_city,
            'country_code' => $countryCode,
            'country_name' => $this->countries->name($countryCode),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function currentOrderItems(Order $order): array
    {
        return $order->items->map(fn ($item, int $index): array => [
            'source_item_id' => null,
            'order_item_id' => $item->getKey(),
            'line_type' => 'product',
            'position' => $index + 1,
            'name' => $item->product_name,
            'description' => null,
            'unit_name' => 'szt.',
            'quantity' => (int) $item->quantity,
            'unit_price_gross' => $this->decimalForForm($item->unit_price_gross, 2),
            'vat_rate' => $this->vatRateForForm($item->vat_rate),
            'vat_code' => null,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function seriesDefaults(
        Invoice $source,
        InvoiceSeries $series,
        string $issueDate,
        array $resolvedSources,
    ): array {
        $reason = $this->correctionReasonDefault($series->default_correction_reason);

        return [
            'reason' => $reason['reason'],
            'other_reason' => $reason['other_reason'],
            'issue_date' => $issueDate,
            'sale_date' => $resolvedSources['sale_date'],
            'payment_method' => $resolvedSources['payment_snapshot']['effective_payment_method'] ?? null,
            'issuer_name' => $resolvedSources['issuer_snapshot']['issuer_name'] ?? null,
            'additional_information' => $this->additionalInformation->render($series, $source->order),
        ];
    }

    /**
     * @param  array{
     *     sale_date: CorrectionSaleDateSource,
     *     issuer: CorrectionIssuerSource,
     *     payment_method: CorrectionPaymentMethodSource
     * }  $sources
     * @return array<string, array{mode: string, help: string}>
     */
    private function sourceModes(array $sources): array
    {
        return [
            'sale_date' => [
                'mode' => $sources['sale_date']->value,
                'help' => $sources['sale_date']->description(),
            ],
            'issuer' => [
                'mode' => $sources['issuer']->value,
                'help' => $sources['issuer']->description(),
            ],
            'payment_method' => [
                'mode' => $sources['payment_method']->value,
                'help' => $sources['payment_method']->description(),
            ],
        ];
    }

    /** @return array{reason: string, other_reason: ?string} */
    private function correctionReasonDefault(?string $configured): array
    {
        $configured = trim((string) $configured);

        if ($configured === '') {
            return [
                'reason' => CorrectionReason::InvoiceError->value,
                'other_reason' => null,
            ];
        }

        $reason = CorrectionReason::tryFrom($configured);

        if ($reason === null) {
            foreach (CorrectionReason::cases() as $candidate) {
                if (mb_strtolower($candidate->label()) === mb_strtolower($configured)) {
                    $reason = $candidate;
                    break;
                }
            }
        }

        if ($reason !== null) {
            return [
                'reason' => $reason->value,
                'other_reason' => null,
            ];
        }

        return [
            'reason' => CorrectionReason::Other->value,
            'other_reason' => $configured,
        ];
    }

    private function integerQuantity(mixed $quantity): int
    {
        return max(0, (int) $quantity);
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private function decimalForForm(mixed $value, int $scale): string
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $normalized)) {
            $normalized = '0';
        }

        [$integer, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        return $integer.'.'.str_pad(substr($fraction, 0, $scale), $scale, '0');
    }

    private function vatRateForForm(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return rtrim(rtrim(trim((string) $value), '0'), '.');
    }
}
