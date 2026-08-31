<?php

namespace Tests\Support\Ksef;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\InvoiceCurrencyConversionService;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\ValueObjects\NbpExchangeRate;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefZeroVatClassification;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionDocumentGenerator;
use Modules\Ksef\Services\Fa3\KsefFa3XmlBuilder;
use Modules\Ksef\Services\KsefInvoiceProvenanceService;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3GeneratedDocument;

trait CreatesKsefFa3CorrectionScenarios
{
    protected function ksefSettings(
        KsefEnvironment $environment = KsefEnvironment::Production,
        KsefZeroVatClassification $zeroClassification = KsefZeroVatClassification::Wdt,
    ): KsefSetting {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'environment' => $environment,
            'send_without_buyer_nip' => false,
            'zero_vat_classification' => $zeroClassification,
        ])->save();

        return $settings->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $orderAttributes
     * @param  array<string, mixed>  $seriesAttributes
     */
    protected function issueKsefRoot(
        array $items = [['unit_price_gross' => '123.00', 'vat_rate' => '23.00']],
        array $orderAttributes = [],
        array $seriesAttributes = [],
    ): Invoice {
        $total = '0.00';
        foreach ($items as $item) {
            $total = app(InvoiceDecimalCalculator::class)->add(
                $total,
                (string) ($item['total_price_gross'] ?? $item['unit_price_gross']),
            );
        }

        $order = $this->createDocumentOrder(array_merge([
            'external_id' => 'KSEF-7C-'.uniqid(),
            'billing_tax_id' => '5260250995',
            'delivery_cost_gross' => '0.00',
            'paid_amount' => '0.00',
            'total_gross' => $total,
        ], $orderAttributes));
        foreach ($items as $index => $item) {
            $gross = (string) ($item['unit_price_gross'] ?? '123.00');
            $this->createDocumentItem($order, array_merge([
                'product_name' => 'Pozycja '.($index + 1),
                'unit_price_gross' => $gross,
                'total_price_gross' => $gross,
                'vat_rate' => '23.00',
            ], $item));
        }

        return app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(attributes: array_merge([
                'include_shipping' => false,
            ], $seriesAttributes)),
            $this->documentContext('2026-08-20 10:00:00'),
        )->fresh('items');
    }

    protected function issueKsefFinancialCorrection(Invoice $root, int $quantity = 2): Invoice
    {
        $items = $this->submittedKsefItems($root);
        $items[0]['quantity'] = $quantity;

        return $this->issueKsefCorrection($root, $items);
    }

    /** @param array<string, mixed> $buyer */
    protected function issueKsefBuyerCorrection(Invoice $root, array $buyer): Invoice
    {
        return $this->issueKsefCorrection($root, buyer: $buyer);
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     * @param  array<string, mixed>|null  $buyer
     */
    protected function issueKsefCorrection(
        Invoice $root,
        ?array $items = null,
        ?array $buyer = null,
    ): Invoice {
        $effective = app(CorrectionSourceStateService::class)->effectiveDocument($root);

        return app(CorrectionService::class)->issue(
            $root,
            $this->ksefCorrectionSeries(),
            $effective->getKey(),
            $effective->lock_version,
            array_replace_recursive($this->ksefCorrectionPayload(), [
                'expected_source_document_id' => $effective->getKey(),
                'expected_source_lock_version' => $effective->lock_version,
                'change_items' => $items !== null,
                'items' => $items ?? [],
                'change_buyer' => $buyer !== null,
                'buyer' => $buyer ?? [],
            ]),
            $this->documentContext('2026-08-21 10:00:00'),
        )->fresh(['items', 'correctedInvoice', 'previousCorrection']);
    }

    /** @return list<array<string, mixed>> */
    protected function submittedKsefItems(Invoice $root): array
    {
        return app(CorrectionSourceStateService::class)
            ->effectiveItems($root)
            ->map(fn (array $item): array => [
                'source_item_id' => $item['source_item_id'],
                'order_item_id' => $item['source_item']->order_item_id,
                'line_type' => $item['snapshot']['line_type'],
                'position' => $item['snapshot']['position'],
                'name' => $item['snapshot']['name'],
                'description' => $item['snapshot']['description'],
                'unit_name' => $item['snapshot']['unit_name'],
                'quantity' => (int) $item['snapshot']['quantity'],
                'unit_price_gross' => $this->ksefTwoDecimals($item['snapshot']['unit_price_gross']),
                'vat_rate' => $item['snapshot']['vat_rate'] !== null
                    ? rtrim(rtrim((string) $item['snapshot']['vat_rate'], '0'), '.')
                    : null,
                'vat_code' => $item['snapshot']['vat_code'],
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function addedKsefItem(int $position, string $gross = '123.00', string $vatRate = '23'): array
    {
        return [
            'source_item_id' => null,
            'order_item_id' => null,
            'line_type' => 'product',
            'position' => $position,
            'name' => 'Dodana pozycja',
            'description' => null,
            'unit_name' => 'szt.',
            'quantity' => 1,
            'unit_price_gross' => $gross,
            'vat_rate' => $vatRate,
            'vat_code' => null,
        ];
    }

    protected function acceptKsefDocument(
        Invoice $document,
        KsefEnvironment $environment = KsefEnvironment::Production,
    ): KsefInvoiceSubmission {
        $sellerNip = preg_replace('/\D+/', '', (string) data_get($document->seller_snapshot, 'tax_id'));
        $this->assertIsString($sellerNip);
        $attempt = ((int) KsefInvoiceSubmission::query()
            ->where('invoice_id', $document->getKey())
            ->where('environment', $environment->value)
            ->max('attempt_number')) + 1;

        return KsefInvoiceSubmission::query()->create([
            'invoice_id' => $document->getKey(),
            'environment' => $environment,
            'context_nip' => $sellerNip,
            'seller_nip' => $sellerNip,
            'attempt_number' => $attempt,
            'status' => KsefInvoiceSubmissionStatus::Accepted,
            'schema_id' => 'FA3',
            'generated_at' => '2026-08-29 10:00:00',
            'payload_xml' => '<Faktura/>',
            'invoice_hash' => base64_encode(hash('sha256', '<Faktura/>', true)),
            'invoice_size' => strlen('<Faktura/>'),
            'ksef_number' => $this->validKsefNumber($sellerNip, str_pad((string) $document->getKey(), 12, '0', STR_PAD_LEFT)),
        ]);
    }

    protected function markKsefOutside(Invoice $invoice, KsefEnvironment $environment): void
    {
        app(KsefInvoiceProvenanceService::class)->markOutsideKsef($invoice, $environment);
    }

    protected function finalizeKsefCorrection(Invoice $correction): Invoice
    {
        return app(InvoiceFinalizationService::class)->finalize($correction);
    }

    protected function makeKsefForeign(Invoice $root, string $currency = 'EUR'): Invoice
    {
        $metadata = app(InvoiceCurrencyConversionService::class)->metadataForHistoricalRate(
            $root->tax_summary_snapshot,
            new NbpExchangeRate(
                source: 'NBP',
                currencyCode: $currency,
                tableType: 'A',
                tableNumber: '137/A/NBP/2026',
                effectiveDate: '2026-07-17',
                referenceDate: '2026-07-20',
                rate: '4.342000',
            ),
            'vat_art_31a_standard_v1',
        );
        $root->forceFill([
            'currency' => $currency,
            'tax_metadata_snapshot' => array_merge($root->tax_metadata_snapshot, $metadata),
        ])->saveQuietly();

        return $root->fresh('items');
    }

    protected function generateKsefCorrection(
        Invoice $correction,
        KsefFa3EligibilityMode $mode = KsefFa3EligibilityMode::Preflight,
    ): KsefFa3GeneratedDocument {
        return app(KsefFa3CorrectionDocumentGenerator::class)->generate(
            $correction,
            CarbonImmutable::parse('2026-08-30 12:34:56', 'Europe/Warsaw'),
            $mode,
        );
    }

    protected function ksefXpath(string $xml): DOMXPath
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fa', KsefFa3XmlBuilder::NAMESPACE);

        return $xpath;
    }

    protected function ksefValue(DOMXPath $xpath, string $expression): string
    {
        $nodes = $xpath->query($expression);
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, $expression);

        return trim((string) $nodes->item(0)?->nodeValue);
    }

    /** @return array<string, mixed> */
    private function ksefCorrectionPayload(): array
    {
        return [
            'expected_source_document_id' => 1,
            'expected_source_lock_version' => 1,
            'expected_lock_version' => 1,
            'correction_series_id' => $this->ksefCorrectionSeries()->getKey(),
            'reason' => CorrectionReason::InvoiceError->value,
            'other_reason' => null,
            'issue_date' => '2026-08-21',
            'sale_date' => '2026-08-20',
            'payment_method' => 'Przelew',
            'issuer_name' => 'Tester Korekty',
            'additional_information' => 'Test FA(3) Korekty',
            'change_items' => false,
            'change_buyer' => false,
            'items' => [],
            'buyer' => [],
        ];
    }

    private function ksefCorrectionSeries(): InvoiceSeries
    {
        return InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Correction)
            ->firstOrFail();
    }

    private function validKsefNumber(string $sellerNip, string $reference): string
    {
        $base = $sellerNip.'-20260819-'.$reference;
        $checksum = 0;
        foreach (str_split($base) as $character) {
            $checksum ^= ord($character);
            for ($bit = 0; $bit < 8; $bit++) {
                $checksum = ($checksum & 0x80) !== 0
                    ? (($checksum << 1) ^ 0x07) & 0xFF
                    : ($checksum << 1) & 0xFF;
            }
        }

        return $base.'-'.strtoupper(str_pad(dechex($checksum), 2, '0', STR_PAD_LEFT));
    }

    private function ksefTwoDecimals(mixed $value): string
    {
        [$integer, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return $integer.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
