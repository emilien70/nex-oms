<?php

namespace Tests\Unit\Ksef;

use App\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Enums\KsefPaymentType;
use Modules\Ksef\Enums\KsefZeroVatClassification;
use Modules\Ksef\Services\Fa3\KsefFa3DocumentGenerator;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionBusinessFingerprintService;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionInvoiceBusinessProjectionV1;
use Modules\Ksef\Services\KsefSettingsService;
use ReflectionMethod;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

final class KsefOfflineTechnicalCorrectionInvoiceBusinessProjectionV1Test extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_basic_invoice_projection_matches_the_frozen_fa3_payload_contract(): void
    {
        $invoice = $this->issueInvoice();
        $this->assertProjectionParity($invoice);
        Http::assertNothingSent();
    }

    public function test_version_two_optional_blocks_and_payment_snapshot_match_the_frozen_contract(): void
    {
        $this->configureOptions(array_fill_keys($this->optionKeys(), true));
        $invoice = $this->issueInvoice(
            order: [
                'external_id' => 'FA3-FINGERPRINT-OPTIONAL',
                'total_gross' => '100.00',
                'billing_email' => 'buyer@example.test',
                'billing_phone' => '+48 500 000 000',
                'payment_status' => 'paid',
                'paid_amount' => '100.00',
                'paid_at' => '2026-07-21 23:30:00',
                'notes' => "Pierwsza linia\nDruga linia",
            ],
            series: [
                'seller_bank_account' => 'PL61 1090 1014 0000 0712 1981 2874',
                'seller_bank_swift' => 'wbkp pl pp',
                'seller_bank_name' => 'Bank Testowy',
                'additional_information_template' => "Nagłówek\n[uwagi_sprzedawcy]",
            ],
        );
        $invoice->items()->firstOrFail()->update(['gtu_codes' => ['GTU_06']]);

        $this->assertProjectionParity($invoice->fresh('items'));
        Http::assertNothingSent();
    }

    public function test_all_supported_vat_buckets_and_decimal_formats_match_the_frozen_contract(): void
    {
        app(KsefSettingsService::class)->get()->forceFill([
            'zero_vat_classification' => KsefZeroVatClassification::Domestic,
        ])->save();
        $invoice = $this->issueInvoice(order: [
            'billing_country_code' => 'DE',
            'billing_tax_id' => 'DE123456789',
        ], items: [
            $this->grossItem('Stawka 23', '123.00', '23.00'),
            $this->grossItem('Stawka 8', '108.00', '8.00'),
            $this->grossItem('Stawka 5', '105.00', '5.00'),
            $this->grossItem('Zero krajowe', '100.00', '0.00'),
            $this->grossItem('WDT', '100.00', '0.00'),
            $this->grossItem('Eksport', '100.00', '0.00'),
        ]);
        $items = $invoice->items()->orderBy('position')->orderBy('id')->get();
        $items[0]->forceFill(['quantity' => '1.2500', 'unit_price_net' => '80.0000'])->saveQuietly();
        $metadata = $invoice->tax_metadata_snapshot;
        $zeroTreatments = [
            (int) $items[3]->getKey() => ['domestic_zero', '0 KR'],
            (int) $items[4]->getKey() => ['wdt', '0 WDT'],
            (int) $items[5]->getKey() => ['export', '0 EX'],
        ];
        foreach ($metadata['ksef_tax']['line_treatments'] as &$treatment) {
            $replacement = $zeroTreatments[(int) ($treatment['invoice_item_id'] ?? 0)] ?? null;
            if ($replacement !== null) {
                [$treatment['treatment'], $treatment['fa3_rate']] = $replacement;
            }
        }
        unset($treatment);
        $invoice->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();

        $this->assertProjectionParity($invoice->fresh('items'));
        Http::assertNothingSent();
    }

    public function test_foreign_currency_converted_vat_matches_the_frozen_contract_without_live_http(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'nbp_table' => 'A']);
        Http::fake([
            '*' => Http::response($this->nbpXml('EUR', '4.3420')),
        ]);
        $invoice = $this->issueInvoice(
            ['currency' => 'EUR'],
            [$this->grossItem('EUR 23', '123.00', '23.00', 'EUR')],
        );

        $this->assertProjectionParity($invoice);
        Http::assertSentCount(1);
    }

    public function test_buyer_identity_variants_match_the_frozen_contract(): void
    {
        $euVat = $this->issueInvoice([
            'billing_country_code' => 'GR',
            'billing_tax_id' => 'EL123456789',
        ]);
        $this->assertProjectionParity($euVat);

        app(KsefSettingsService::class)->get()->forceFill(['send_without_buyer_nip' => true])->save();
        $withoutIdentifier = $this->issueInvoice(['billing_tax_id' => null]);
        $this->assertProjectionParity($withoutIdentifier);
        Http::assertNothingSent();
    }

    public function test_version_one_legacy_payment_and_pre_snapshot_documents_keep_their_frozen_semantics(): void
    {
        $versionOne = $this->issueInvoice();
        $metadata = $versionOne->tax_metadata_snapshot;
        $metadata['ksef_document']['version'] = 1;
        unset($metadata['ksef_document']['options']['include_seller_vat_prefix']);
        $payment = $versionOne->payment_snapshot;
        unset($payment['ksef_payment']);
        $versionOne->forceFill([
            'tax_metadata_snapshot' => $metadata,
            'payment_snapshot' => $payment,
        ])->saveQuietly();
        $this->assertProjectionParity($versionOne->fresh('items'));

        app(KsefSettingsService::class)->get()->forceFill([
            'default_payment_type' => KsefPaymentType::Card,
        ])->save();
        $directPayment = $this->issueInvoice(['payment_method' => 'Operator płatności']);
        $this->assertSame('2', data_get(
            app(KsefOfflineTechnicalCorrectionInvoiceBusinessProjectionV1::class)->project($directPayment),
            'invoice.payment.method_code',
        ));
        $this->assertProjectionParity($directPayment);

        $legacy = $this->issueInvoice();
        $metadata = $legacy->tax_metadata_snapshot;
        unset($metadata['ksef_document']);
        $legacy->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $this->assertProjectionParity($legacy->fresh('items'));
        Http::assertNothingSent();
    }

    private function assertProjectionParity(Invoice $invoice): void
    {
        $generated = app(KsefFa3DocumentGenerator::class)->generate(
            $invoice,
            CarbonImmutable::parse('2026-09-06T12:00:00Z'),
            KsefFa3EligibilityMode::Preflight,
        );
        $service = app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class);
        $payloadProjection = new ReflectionMethod($service, 'fromPayloadV1');

        $this->assertSame(
            $payloadProjection->invoke($service, $generated->xml),
            app(KsefOfflineTechnicalCorrectionInvoiceBusinessProjectionV1::class)->project($invoice),
        );
        $this->assertSame(
            $service->fromPayload($generated->xml, 1),
            $service->fromInvoice($invoice, 1),
        );
    }

    private function issueInvoice(array $order = [], array $items = [[]], array $series = []): Invoice
    {
        $orderModel = $this->createDocumentOrder(array_merge([
            'external_id' => 'FA3-FINGERPRINT-'.uniqid(),
            'billing_tax_id' => '5260250995',
            'delivery_cost_gross' => '0.00',
            'paid_amount' => '0.00',
        ], $order));
        foreach ($items as $item) {
            $this->createDocumentItem($orderModel, $item);
        }

        return app(InvoiceIssuingService::class)->issue(
            $orderModel,
            $this->createDocumentSeries(InvoiceDocumentType::Invoice, array_merge([
                'include_shipping' => false,
            ], $series)),
            $this->documentContext(),
        )->refresh()->load('items');
    }

    /** @return array<string, mixed> */
    private function grossItem(string $name, string $gross, string $vatRate, string $currency = 'PLN'): array
    {
        return [
            'product_name' => $name,
            'quantity' => 1,
            'unit_price_gross' => $gross,
            'total_price_gross' => $gross,
            'currency' => $currency,
            'vat_rate' => $vatRate,
        ];
    }

    /** @param array<string, bool> $overrides */
    private function configureOptions(array $overrides): void
    {
        app(KsefSettingsService::class)->get()->forceFill(
            array_replace(array_fill_keys($this->optionKeys(), false), $overrides),
        )->save();
    }

    /** @return array<int, string> */
    private function optionKeys(): array
    {
        return [
            'include_recipient_data',
            'include_buyer_contact_data',
            'include_additional_information',
            'include_order_reference',
            'include_bank_account',
            'include_gtu',
            'include_seller_vat_prefix',
        ];
    }

    private function nbpXml(string $currency, string $mid): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<ExchangeRatesSeries><Table>A</Table><Code>'.$currency.'</Code><Rates><Rate>'
            .'<No>137/A/NBP/2026</No><EffectiveDate>2026-07-17</EffectiveDate><Mid>'.$mid.'</Mid>'
            .'</Rate></Rates></ExchangeRatesSeries>';
    }
}
