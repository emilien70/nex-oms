<?php

namespace Modules\Invoices\Services;

use BackedEnum;
use Modules\Invoices\Enums\CorrectionIssuerSource;
use Modules\Invoices\Enums\CorrectionPaymentMethodSource;
use Modules\Invoices\Enums\CorrectionSaleDateSource;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;

class CorrectionSeriesSourceResolver
{
    /**
     * @return array{
     *     sale_date: ?string,
     *     issuer_snapshot: array<string, mixed>,
     *     payment_snapshot: array<string, mixed>,
     *     sources: array{
     *         sale_date: CorrectionSaleDateSource,
     *         issuer: CorrectionIssuerSource,
     *         payment_method: CorrectionPaymentMethodSource
     *     }
     * }
     */
    public function forIssue(Invoice $source, InvoiceSeries $series, string $issueDate): array
    {
        $saleDateSource = $this->enumFromSnapshot(
            CorrectionSaleDateSource::class,
            $series->getRawOriginal('correction_sale_date_source'),
        );
        $issuerSource = $this->enumFromSnapshot(
            CorrectionIssuerSource::class,
            $series->getRawOriginal('correction_issuer_source'),
        );
        $paymentMethodSource = $this->enumFromSnapshot(
            CorrectionPaymentMethodSource::class,
            $series->getRawOriginal('correction_payment_method_source'),
        );

        if (! $saleDateSource instanceof CorrectionSaleDateSource
            || ! $issuerSource instanceof CorrectionIssuerSource
            || ! $paymentMethodSource instanceof CorrectionPaymentMethodSource) {
            throw new InvoiceDomainException(
                'correction_series_settings_incomplete',
                'Nie można wystawić Korekty, ponieważ ustawienia źródeł danych serii są niekompletne.',
            );
        }

        $issuer = $issuerSource === CorrectionIssuerSource::Series
            ? [
                'issuer_name' => $this->nullableText($series->issuer_name),
                'place_of_issue' => $this->nullableText($series->place_of_issue),
            ]
            : $this->snapshot($source->issuer_snapshot);

        $payment = $this->snapshot($source->payment_snapshot);
        $payment['effective_payment_method'] = match ($paymentMethodSource) {
            CorrectionPaymentMethodSource::SourceInvoice => $this->nullableText(
                $payment['effective_payment_method'] ?? null,
            ),
            CorrectionPaymentMethodSource::Fixed => $this->nullableText($series->fixed_payment_method),
            CorrectionPaymentMethodSource::None => null,
        };

        return [
            'sale_date' => $saleDateSource === CorrectionSaleDateSource::IssueDate
                ? $issueDate
                : $source->sale_date?->toDateString(),
            'issuer_snapshot' => $issuer,
            'payment_snapshot' => $payment,
            'sources' => [
                'sale_date' => $saleDateSource,
                'issuer' => $issuerSource,
                'payment_method' => $paymentMethodSource,
            ],
        ];
    }

    /**
     * @return array{
     *     sale_date: ?string,
     *     issuer_snapshot: array<string, mixed>,
     *     payment_snapshot: array<string, mixed>,
     *     sources: array{
     *         sale_date: CorrectionSaleDateSource,
     *         issuer: CorrectionIssuerSource,
     *         payment_method: CorrectionPaymentMethodSource
     *     }
     * }
     */
    public function forUpdate(Invoice $source, Invoice $correction, string $issueDate): array
    {
        $settings = $this->snapshot($correction->series_settings_snapshot);
        $saleDateSource = $this->enumFromSnapshot(
            CorrectionSaleDateSource::class,
            $settings['correction_sale_date_source'] ?? null,
        );
        $issuerSource = $this->enumFromSnapshot(
            CorrectionIssuerSource::class,
            $settings['correction_issuer_source'] ?? null,
        );
        $paymentMethodSource = $this->enumFromSnapshot(
            CorrectionPaymentMethodSource::class,
            $settings['correction_payment_method_source'] ?? null,
        );

        if (! $saleDateSource instanceof CorrectionSaleDateSource
            || ! $issuerSource instanceof CorrectionIssuerSource
            || ! $paymentMethodSource instanceof CorrectionPaymentMethodSource) {
            throw new InvoiceDomainException(
                'correction_edit_series_settings_incomplete',
                'Nie można edytować Korekty, ponieważ zapisane ustawienia źródeł danych dokumentu są niekompletne.',
            );
        }

        $issuer = $issuerSource === CorrectionIssuerSource::SourceInvoice
            ? $this->snapshot($source->issuer_snapshot)
            : $this->snapshot($correction->issuer_snapshot);

        $payment = $paymentMethodSource === CorrectionPaymentMethodSource::SourceInvoice
            ? $this->snapshot($source->payment_snapshot)
            : $this->snapshot($correction->payment_snapshot);
        $payment['effective_payment_method'] = match ($paymentMethodSource) {
            CorrectionPaymentMethodSource::SourceInvoice => $this->nullableText(
                $payment['effective_payment_method'] ?? null,
            ),
            CorrectionPaymentMethodSource::Fixed => $this->nullableText(
                data_get($correction->payment_snapshot, 'effective_payment_method'),
            ),
            CorrectionPaymentMethodSource::None => null,
        };

        return [
            'sale_date' => $saleDateSource === CorrectionSaleDateSource::IssueDate
                ? $issueDate
                : $source->sale_date?->toDateString(),
            'issuer_snapshot' => $issuer,
            'payment_snapshot' => $payment,
            'sources' => [
                'sale_date' => $saleDateSource,
                'issuer' => $issuerSource,
                'payment_method' => $paymentMethodSource,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(mixed $snapshot): array
    {
        return is_array($snapshot) ? $snapshot : [];
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    private function enumFromSnapshot(string $enum, mixed $value): ?BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }

        return is_string($value) ? $enum::tryFrom($value) : null;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}
