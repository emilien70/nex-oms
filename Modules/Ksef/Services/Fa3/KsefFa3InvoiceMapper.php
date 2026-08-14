<?php

namespace Modules\Ksef\Services\Fa3;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Collection;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceTaxIdentityNormalizer;
use Modules\Ksef\Services\KsefFa3BuyerIdentityResolver;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3DocumentData;

class KsefFa3InvoiceMapper
{
    private const BUCKETS = [
        'standard_1',
        'standard_2',
        'standard_3',
        'domestic_zero',
        'wdt',
        'export',
    ];

    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoiceTaxIdentityNormalizer $taxIdentity,
        private readonly KsefFa3BuyerIdentityResolver $buyerIdentity,
    ) {}

    public function map(Invoice $invoice, DateTimeInterface $generatedAt): KsefFa3DocumentData
    {
        $items = $invoice->items()->orderBy('position')->orderBy('id')->get();
        $ksefTax = data_get($invoice->tax_metadata_snapshot, 'ksef_tax');
        $treatments = collect(is_array($ksefTax) ? ($ksefTax['line_treatments'] ?? []) : [])
            ->filter(static fn (mixed $value): bool => is_array($value))
            ->keyBy(static fn (array $value): int => (int) ($value['invoice_item_id'] ?? 0));

        [$lines, $taxBuckets] = $this->mapLinesAndSummary($invoice, $items, $treatments);
        $annotations = is_array($ksefTax['annotations'] ?? null) ? $ksefTax['annotations'] : [];
        $hasWdt = $treatments->contains(
            static fn (array $treatment): bool => ($treatment['treatment'] ?? null) === 'wdt',
        );

        return new KsefFa3DocumentData(
            generatedAt: DateTimeImmutable::createFromInterface($generatedAt)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
            seller: $this->seller($invoice->seller_snapshot ?? [], $hasWdt),
            buyer: $this->buyer($invoice->buyer_snapshot ?? []),
            invoice: [
                'currency' => strtoupper(trim((string) $invoice->currency)),
                'issue_date' => $invoice->issue_date?->format('Y-m-d') ?? '',
                'place_of_issue' => $this->optionalString(data_get($invoice->issuer_snapshot, 'place_of_issue')),
                'number' => trim((string) $invoice->number),
                'sale_date' => $invoice->sale_date?->format('Y-m-d') ?? '',
                'total_gross' => $this->money($invoice->total_gross),
            ],
            taxBuckets: $taxBuckets,
            annotations: [
                'cash_accounting' => ($annotations['cash_accounting'] ?? null) === true,
                'self_billing' => ($annotations['self_billing'] ?? null) === true,
                'reverse_charge' => ($annotations['reverse_charge'] ?? null) === true,
                'split_payment' => ($annotations['split_payment'] ?? null) === true,
                'new_transport_mean' => ($annotations['new_transport_mean'] ?? null) === true,
                'triangular_transaction' => ($annotations['triangular_transaction'] ?? null) === true,
                'margin_scheme' => ($annotations['margin_scheme'] ?? null) === true,
            ],
            lines: $lines,
            registrations: array_filter([
                'regon' => $this->optionalString(data_get($invoice->seller_snapshot, 'regon')),
                'bdo' => $this->optionalString(data_get($invoice->seller_snapshot, 'bdo')),
            ], static fn (?string $value): bool => $value !== null),
        );
    }

    /**
     * @param  Collection<int, InvoiceItem>  $items
     * @param  Collection<int, array<string, mixed>>  $treatments
     * @return array{0: array<int, array<string, int|string>>, 1: array<string, array<string, string>|null>}
     */
    private function mapLinesAndSummary(Invoice $invoice, Collection $items, Collection $treatments): array
    {
        $lines = [];
        $buckets = array_fill_keys(self::BUCKETS, null);
        $totalNet = '0.00';
        $totalVat = '0.00';
        $totalGross = '0.00';
        $sourceTaxIdentities = [];

        foreach ($items as $item) {
            $treatment = $treatments->get($item->getKey());
            if (! is_array($treatment)) {
                throw $this->financialError();
            }

            $bucketKey = $this->bucketKey($treatment);
            $net = $this->money($item->total_net);
            $vat = $this->money($item->total_vat);
            $gross = $this->money($item->total_gross);
            $sourceTaxIdentities[] = (string) ($treatment['tax_identity'] ?? '');
            $buckets[$bucketKey] ??= ['net' => '0.00', 'vat' => '0.00'];
            $buckets[$bucketKey]['net'] = $this->decimal->add($buckets[$bucketKey]['net'], $net);
            $buckets[$bucketKey]['vat'] = $this->decimal->add($buckets[$bucketKey]['vat'], $vat);
            $totalNet = $this->decimal->add($totalNet, $net);
            $totalVat = $this->decimal->add($totalVat, $vat);
            $totalGross = $this->decimal->add($totalGross, $gross);

            $lines[] = [
                'position' => $item->position,
                'name' => (string) $item->name,
                'unit_name' => (string) $item->unit_name,
                'quantity' => $this->decimalValue($item->quantity, 4),
                'unit_price_net' => $this->decimalValue($item->unit_price_net, 4),
                'total_net' => $net,
                'fa3_rate' => (string) ($treatment['fa3_rate'] ?? ''),
            ];
        }

        if ($this->decimal->compare($totalNet, (string) $invoice->total_net) !== 0
            || $this->decimal->compare($totalVat, (string) $invoice->total_vat) !== 0
            || $this->decimal->compare($totalGross, (string) $invoice->total_gross) !== 0) {
            throw $this->financialError();
        }

        if (strtoupper(trim((string) $invoice->currency)) !== 'PLN') {
            $this->addConvertedVat($invoice, $buckets, $sourceTaxIdentities);
        }

        return [$lines, $buckets];
    }

    /**
     * @param  array<string, array<string, string>|null>  $buckets
     * @param  array<int, string>  $sourceTaxIdentities
     */
    private function addConvertedVat(Invoice $invoice, array &$buckets, array $sourceTaxIdentities): void
    {
        try {
            $this->mapConvertedVat($invoice, $buckets, $sourceTaxIdentities);
        } catch (InvoiceDomainException $exception) {
            if ($exception->errorCode() === 'ksef_fa3_currency_snapshot_invalid') {
                throw $exception;
            }

            throw $this->currencyError($exception);
        }
    }

    /**
     * @param  array<string, array<string, string>|null>  $buckets
     * @param  array<int, string>  $sourceTaxIdentities
     */
    private function mapConvertedVat(Invoice $invoice, array &$buckets, array $sourceTaxIdentities): void
    {
        $metadata = $invoice->tax_metadata_snapshot ?? [];
        $conversion = $metadata['currency_conversion'] ?? null;
        $summary = $metadata['converted_tax_summary'] ?? null;
        $currency = strtoupper(trim((string) $invoice->currency));

        if (! is_array($conversion) || ! is_array($summary)
            || ($conversion['version'] ?? null) !== 1
            || strtoupper(trim((string) ($conversion['source_currency'] ?? ''))) !== $currency
            || ($conversion['target_currency'] ?? null) !== 'PLN'
            || ($conversion['rounding_mode'] ?? null) !== 'half_up'
            || ($conversion['result_scale'] ?? null) !== 2
            || ($summary['currency'] ?? null) !== 'PLN'
            || ! is_array($summary['groups'] ?? null)) {
            throw $this->currencyError();
        }

        $groups = [];
        $summaryNet = '0.00';
        $summaryVat = '0.00';
        $summaryGross = '0.00';
        foreach ($summary['groups'] as $group) {
            if (! is_array($group)) {
                throw $this->currencyError();
            }

            $identity = $this->taxIdentity->normalize($group['vat_rate'] ?? null, $group['vat_code'] ?? null);
            $key = $this->taxIdentity->key($identity);
            if ($key === null || isset($groups[$key])) {
                throw $this->currencyError();
            }

            $net = $this->strictMoney($group['net'] ?? null);
            $vat = $this->strictMoney($group['vat'] ?? null);
            $gross = $this->strictMoney($group['gross'] ?? null);
            if ($this->decimal->compare($this->decimal->add($net, $vat), $gross) !== 0) {
                throw $this->currencyError();
            }

            $groups[$key] = $vat;
            $summaryNet = $this->decimal->add($summaryNet, $net);
            $summaryVat = $this->decimal->add($summaryVat, $vat);
            $summaryGross = $this->decimal->add($summaryGross, $gross);
        }

        $expectedIdentities = array_values(array_unique($sourceTaxIdentities));
        sort($expectedIdentities, SORT_STRING);
        $actualIdentities = array_keys($groups);
        sort($actualIdentities, SORT_STRING);
        if ($expectedIdentities !== $actualIdentities
            || $this->decimal->compare($summaryNet, $this->strictMoney($summary['total_net'] ?? null)) !== 0
            || $this->decimal->compare($summaryVat, $this->strictMoney($summary['total_vat'] ?? null)) !== 0
            || $this->decimal->compare($summaryGross, $this->strictMoney($summary['total_gross'] ?? null)) !== 0) {
            throw $this->currencyError();
        }

        foreach ([
            'standard_1' => ['rate:23.00', 'rate:22.00'],
            'standard_2' => ['rate:8.00', 'rate:7.00'],
            'standard_3' => ['rate:5.00'],
        ] as $bucketKey => $identities) {
            if ($buckets[$bucketKey] === null) {
                continue;
            }

            $plnVat = '0.00';
            foreach ($identities as $identity) {
                if (isset($groups[$identity])) {
                    $plnVat = $this->decimal->add($plnVat, $groups[$identity]);
                }
            }
            $buckets[$bucketKey]['pln_vat'] = $plnVat;
        }
    }

    /** @param array<string, mixed> $treatment */
    private function bucketKey(array $treatment): string
    {
        return match ($treatment['treatment'] ?? null) {
            'domestic_zero' => 'domestic_zero',
            'wdt' => 'wdt',
            'export' => 'export',
            'standard' => match ($treatment['fa3_rate'] ?? null) {
                '23', '22' => 'standard_1',
                '8', '7' => 'standard_2',
                '5' => 'standard_3',
                default => throw $this->financialError(),
            },
            default => throw $this->financialError(),
        };
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function seller(array $snapshot, bool $hasWdt): array
    {
        return [
            'taxpayer_prefix' => $hasWdt ? 'PL' : null,
            'nip' => $this->buyerIdentity->normalizePolishNip($snapshot['tax_id'] ?? null) ?? '',
            'name' => trim((string) ($snapshot['name'] ?? '')),
            'address' => $this->address($snapshot),
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function buyer(array $snapshot): array
    {
        $identity = is_array($snapshot['tax_identity'] ?? null) ? $snapshot['tax_identity'] : [];
        $name = $this->optionalString($snapshot['company_name'] ?? null)
            ?? $this->optionalString($snapshot['name'] ?? null);

        return [
            'identity_type' => (string) ($identity['type'] ?? ''),
            'identity_country_code' => $this->optionalString($identity['country_code'] ?? null),
            'identity_identifier' => $this->optionalString($identity['identifier'] ?? null),
            'name' => $name,
            'address' => $this->address($snapshot, false),
            'jst' => (bool) data_get($snapshot, 'subject_flags.jst'),
            'vat_group' => (bool) data_get($snapshot, 'subject_flags.vat_group'),
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, string>|null
     */
    private function address(array $snapshot, bool $required = true): ?array
    {
        $street = $this->optionalString($snapshot['street'] ?? null);
        $building = $this->optionalString($snapshot['building_number'] ?? null);
        $apartment = $this->optionalString($snapshot['apartment_number'] ?? null);
        $number = $building;
        if ($apartment !== null) {
            $number = $number !== null ? $number.'/'.$apartment : $apartment;
        }

        $line1 = trim(implode(' ', array_filter([$street, $number], static fn (?string $value): bool => $value !== null)));
        if ($line1 === '') {
            return $required ? [
                'country_code' => strtoupper(trim((string) ($snapshot['country_code'] ?? ''))),
                'line_1' => '',
            ] : null;
        }

        $line2 = trim(implode(' ', array_filter([
            $this->optionalString($snapshot['postal_code'] ?? null),
            $this->optionalString($snapshot['city'] ?? null),
        ], static fn (?string $value): bool => $value !== null)));

        return array_filter([
            'country_code' => strtoupper(trim((string) ($snapshot['country_code'] ?? ''))),
            'line_1' => $line1,
            'line_2' => $line2 !== '' ? $line2 : null,
        ], static fn (?string $value): bool => $value !== null);
    }

    private function money(mixed $value): string
    {
        return $this->decimalValue($value, 2);
    }

    private function decimalValue(mixed $value, int $scale): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw $this->financialError();
        }

        try {
            return $this->decimal->normalize($value, $scale);
        } catch (InvoiceDomainException $exception) {
            throw $this->financialError($exception);
        }
    }

    private function strictMoney(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^-?(?:0|[1-9]\d*)\.\d{2}$/', $value) !== 1) {
            throw $this->currencyError();
        }

        return $this->decimal->normalize($value, 2);
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function financialError(?\Throwable $previous = null): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'ksef_fa3_financial_snapshot_invalid',
            'Wartości finansowe Faktury są niespójne i nie pozwalają utworzyć FA(3).',
            [],
            $previous,
        );
    }

    private function currencyError(?\Throwable $previous = null): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'ksef_fa3_currency_snapshot_invalid',
            'Snapshot przeliczenia podatku do PLN jest niekompletny lub niespójny.',
            [],
            $previous,
        );
    }
}
