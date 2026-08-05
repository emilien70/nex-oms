<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use App\Support\CountryCatalog;
use BackedEnum;
use Modules\Invoices\Enums\CorrectionIssuerSource;
use Modules\Invoices\Enums\CorrectionPaymentMethodSource;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\CorrectionSaleDateSource;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;

class CorrectionViewModelFactory
{
    public function __construct(
        private readonly CorrectionSourceStateService $sourceState,
        private readonly CorrectionSeriesResolver $seriesResolver,
        private readonly AdditionalInformationRenderer $additionalInformation,
        private readonly CountryCatalog $countries,
    ) {}

    /** @return array<string, mixed> */
    public function make(Invoice $sourceInvoice, ?int $seriesId = null): array
    {
        $this->sourceState->assertSourceInvoice($sourceInvoice);
        $sourceInvoice->loadMissing(['order.items', 'series.defaultCorrectionSeries']);

        $series = $this->seriesResolver->resolve($sourceInvoice, $seriesId);
        $effective = $this->sourceState->effectiveDocument($sourceInvoice);
        $buyer = $this->sourceState->effectiveBuyer($sourceInvoice);
        $items = $this->sourceState->effectiveItems($sourceInvoice)
            ->map(fn (array $item): array => $this->formItem($item))
            ->values();

        return [
            'sourceInvoice' => $sourceInvoice,
            'order' => $sourceInvoice->order,
            'correctionSeries' => $this->seriesResolver->active(),
            'selectedSeries' => $series,
            'reasons' => CorrectionReason::cases(),
            'items' => $items,
            'buyer' => $buyer,
            'currentBuyer' => $this->currentBuyer($sourceInvoice->order),
            'currentOrderItems' => $this->currentOrderItems($sourceInvoice->order),
            'countries' => $this->countries->all(),
            'defaults' => $this->seriesDefaults($sourceInvoice, $effective, $series),
        ];
    }

    /** @return array<string, mixed> */
    private function formItem(array $item): array
    {
        $snapshot = $item['snapshot'];

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
            'vat_rate' => $snapshot['vat_rate'] !== null
                ? $this->decimalForForm($snapshot['vat_rate'], 2)
                : null,
            'vat_code' => $snapshot['vat_code'] ?? null,
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
            'vat_rate' => $item->vat_rate !== null ? $this->decimalForForm($item->vat_rate, 2) : null,
            'vat_code' => null,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function seriesDefaults(Invoice $source, Invoice $effective, InvoiceSeries $series): array
    {
        $payment = is_array($effective->payment_snapshot) ? $effective->payment_snapshot : [];
        $sourceIssuer = is_array($source->issuer_snapshot) ? $source->issuer_snapshot : [];
        $reason = $this->correctionReasonDefault($series->default_correction_reason);

        return [
            'reason' => $reason['reason'],
            'other_reason' => $reason['other_reason'],
            'issue_date' => now(config('app.timezone'))->toDateString(),
            'sale_date' => $series->correction_sale_date_source === CorrectionSaleDateSource::IssueDate
                ? now(config('app.timezone'))->toDateString()
                : $source->sale_date?->toDateString(),
            'payment_method' => match ($series->correction_payment_method_source) {
                CorrectionPaymentMethodSource::None => null,
                CorrectionPaymentMethodSource::Fixed => $series->fixed_payment_method,
                default => $payment['effective_payment_method'] ?? null,
            },
            'issuer_name' => $series->correction_issuer_source === CorrectionIssuerSource::Series
                ? $series->issuer_name
                : ($sourceIssuer['issuer_name'] ?? null),
            'additional_information' => $this->additionalInformation->render($series, $source->order),
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
}
