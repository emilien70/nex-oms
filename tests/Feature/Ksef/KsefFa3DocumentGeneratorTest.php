<?php

namespace Tests\Feature\Ksef;

use App\Models\Currency;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Enums\KsefZeroVatClassification;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\Fa3\KsefFa3DocumentGenerator;
use Modules\Ksef\Services\Fa3\KsefFa3XmlBuilder;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3GeneratedDocument;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefFa3DocumentGeneratorTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nbp.retries' => 0, 'nbp.retry_delay_ms' => 0]);
        Http::preventStrayRequests();
    }

    public function test_it_generates_deterministic_schema_valid_core_invoice_from_snapshots(): void
    {
        $invoice = $this->issueInvoice(
            [
                'billing_tax_id' => 'PL526-025-09-95',
                'billing_company_name' => 'Kupujący & Synowie <PL>',
                'paid_amount' => '50.00',
            ],
            [[
                'product_name' => 'Usługa & wdrożenie <NEX> "Pro"',
                'unit_price_gross' => '123.00',
                'total_price_gross' => '123.00',
                'vat_rate' => '23.00',
            ]],
            [
                'seller_tax_id' => 'PL987-654-32-10',
                'seller_name' => 'NEX & Partnerzy <Śląsk>',
                'seller_regon' => '123456785',
                'seller_bdo' => '123456789',
            ],
        );
        $before = $invoice->fresh()->getAttributes();
        $itemsBefore = $invoice->items()->orderBy('position')->orderBy('id')->get()
            ->map(fn ($item): array => $item->getAttributes())->all();
        $settingsBefore = KsefSetting::query()->firstOrFail()->getAttributes();
        $credentialsBefore = KsefCredential::query()->count();
        $generatedAt = CarbonImmutable::parse('2026-08-14 10:11:12', 'Europe/Warsaw');

        $first = $this->generate($invoice, $generatedAt);
        $second = $this->generate($invoice, $generatedAt);
        $xpath = $this->xpath($first->xml);

        $this->assertSame($first->xml, $second->xml);
        $this->assertSame('2026-08-14T08:11:12Z', $first->generatedAt);
        $this->assertSame('FA (3) 1-0E', $first->schemaId);
        $this->assertSame('FA', $this->value($xpath, '/fa:Faktura/fa:Naglowek/fa:KodFormularza'));
        $this->assertSame('FA (3)', $this->value($xpath, '/fa:Faktura/fa:Naglowek/fa:KodFormularza/@kodSystemowy'));
        $this->assertSame('1-0E', $this->value($xpath, '/fa:Faktura/fa:Naglowek/fa:KodFormularza/@wersjaSchemy'));
        $this->assertSame('3', $this->value($xpath, '/fa:Faktura/fa:Naglowek/fa:WariantFormularza'));
        $this->assertSame('NEX-OMS', $this->value($xpath, '/fa:Faktura/fa:Naglowek/fa:SystemInfo'));
        $this->assertSame(0, $xpath->query('/fa:Faktura/fa:Podmiot1/fa:PrefiksPodatnika')->length);
        $this->assertSame('9876543210', $this->value($xpath, '/fa:Faktura/fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:NIP'));
        $this->assertSame('NEX & Partnerzy <Śląsk>', $this->value($xpath, '/fa:Faktura/fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:Nazwa'));
        $this->assertSame('PL', $this->value($xpath, '/fa:Faktura/fa:Podmiot1/fa:Adres/fa:KodKraju'));
        $this->assertSame('Sprzedawcy 1', $this->value($xpath, '/fa:Faktura/fa:Podmiot1/fa:Adres/fa:AdresL1'));
        $this->assertSame('40-001 Katowice', $this->value($xpath, '/fa:Faktura/fa:Podmiot1/fa:Adres/fa:AdresL2'));
        $this->assertSame('5260250995', $this->value($xpath, '/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NIP'));
        $this->assertSame('Kupujący & Synowie <PL>', $this->value($xpath, '/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa'));
        $this->assertSame('PL', $this->value($xpath, '/fa:Faktura/fa:Podmiot2/fa:Adres/fa:KodKraju'));
        $this->assertSame('Fakturowa 10/2', $this->value($xpath, '/fa:Faktura/fa:Podmiot2/fa:Adres/fa:AdresL1'));
        $this->assertSame('2', $this->value($xpath, '/fa:Faktura/fa:Podmiot2/fa:JST'));
        $this->assertSame('2', $this->value($xpath, '/fa:Faktura/fa:Podmiot2/fa:GV'));
        $this->assertSame('PLN', $this->value($xpath, '/fa:Faktura/fa:Fa/fa:KodWaluty'));
        $this->assertSame($invoice->number, $this->value($xpath, '/fa:Faktura/fa:Fa/fa:P_2'));
        $this->assertSame($invoice->sale_date->format('Y-m-d'), $this->value($xpath, '/fa:Faktura/fa:Fa/fa:P_6'));
        $this->assertSame($invoice->total_gross, $this->value($xpath, '/fa:Faktura/fa:Fa/fa:P_15'));
        $this->assertNotSame($invoice->amount_due, $this->value($xpath, '/fa:Faktura/fa:Fa/fa:P_15'));
        $this->assertSame('Usługa & wdrożenie <NEX> "Pro"', $this->value($xpath, '/fa:Faktura/fa:Fa/fa:FaWiersz/fa:P_7'));
        $this->assertStringContainsString('&amp;', $first->xml);
        $this->assertStringContainsString('&lt;NEX&gt;', $first->xml);
        $this->assertSame('123456785', $this->value($xpath, '/fa:Faktura/fa:Stopka/fa:Rejestry/fa:REGON'));
        $this->assertSame('123456789', $this->value($xpath, '/fa:Faktura/fa:Stopka/fa:Rejestry/fa:BDO'));
        $this->assertSame('2', $this->value($xpath, '//fa:Adnotacje/fa:P_16'));
        $this->assertSame('2', $this->value($xpath, '//fa:Adnotacje/fa:P_17'));
        $this->assertSame('2', $this->value($xpath, '//fa:Adnotacje/fa:P_18'));
        $this->assertSame('2', $this->value($xpath, '//fa:Adnotacje/fa:P_18A'));
        $this->assertSame('1', $this->value($xpath, '//fa:Adnotacje/fa:Zwolnienie/fa:P_19N'));
        $this->assertSame('1', $this->value($xpath, '//fa:Adnotacje/fa:NoweSrodkiTransportu/fa:P_22N'));
        $this->assertSame('2', $this->value($xpath, '//fa:Adnotacje/fa:P_23'));
        $this->assertSame('1', $this->value($xpath, '//fa:Adnotacje/fa:PMarzy/fa:P_PMarzyN'));
        $this->assertSame(0, $xpath->query('//fa:Platnosc|//fa:Podmiot3|//fa:GTU')->length);
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", $first->xml);
        $this->assertStringNotContainsString("\r\n", $first->xml);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame(
            $itemsBefore,
            $invoice->items()->orderBy('position')->orderBy('id')->get()
                ->map(fn ($item): array => $item->getAttributes())->all(),
        );
        $this->assertSame($settingsBefore, KsefSetting::query()->firstOrFail()->getAttributes());
        $this->assertSame($credentialsBefore, KsefCredential::query()->count());
        Http::assertNothingSent();
    }

    public function test_standard_rates_are_aggregated_into_the_three_fa3_buckets(): void
    {
        $invoice = $this->issueInvoice(items: [
            $this->grossItem('Pozycja 23', '123.00', '23.00'),
            $this->grossItem('Pozycja 22', '122.00', '22.00'),
            $this->grossItem('Pozycja 8', '108.00', '8.00'),
            $this->grossItem('Pozycja 7', '107.00', '7.00'),
            $this->grossItem('Pozycja 5', '105.00', '5.00'),
        ]);
        $xpath = $this->xpath($this->generate($invoice)->xml);

        $this->assertSame('200.00', $this->value($xpath, '//fa:Fa/fa:P_13_1'));
        $this->assertSame('45.00', $this->value($xpath, '//fa:Fa/fa:P_14_1'));
        $this->assertSame('200.00', $this->value($xpath, '//fa:Fa/fa:P_13_2'));
        $this->assertSame('15.00', $this->value($xpath, '//fa:Fa/fa:P_14_2'));
        $this->assertSame('100.00', $this->value($xpath, '//fa:Fa/fa:P_13_3'));
        $this->assertSame('5.00', $this->value($xpath, '//fa:Fa/fa:P_14_3'));
        $this->assertSame(5, $xpath->query('//fa:Fa/fa:FaWiersz')->length);
    }

    public function test_mixed_zero_rate_treatments_use_separate_frozen_buckets(): void
    {
        $this->settings()->forceFill(['zero_vat_classification' => KsefZeroVatClassification::Domestic])->save();
        $invoice = $this->issueInvoice(
            ['billing_country_code' => 'DE', 'billing_tax_id' => 'DE123456789'],
            [
                $this->grossItem('Zero KR', '10.00', '0.00'),
                $this->grossItem('Zero WDT', '20.00', '0.00'),
                $this->grossItem('Zero EX', '30.00', '0.00'),
            ],
        );
        $metadata = $invoice->tax_metadata_snapshot;
        $metadata['ksef_tax']['line_treatments'][1]['treatment'] = 'wdt';
        $metadata['ksef_tax']['line_treatments'][1]['fa3_rate'] = '0 WDT';
        $metadata['ksef_tax']['line_treatments'][2]['treatment'] = 'export';
        $metadata['ksef_tax']['line_treatments'][2]['fa3_rate'] = '0 EX';
        $invoice->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $this->settings()->forceFill(['zero_vat_classification' => KsefZeroVatClassification::Export])->save();

        $xpath = $this->xpath($this->generate($invoice->refresh())->xml);

        $this->assertSame('10.00', $this->value($xpath, '//fa:Fa/fa:P_13_6_1'));
        $this->assertSame('20.00', $this->value($xpath, '//fa:Fa/fa:P_13_6_2'));
        $this->assertSame('30.00', $this->value($xpath, '//fa:Fa/fa:P_13_6_3'));
        $this->assertSame(['0 KR', '0 WDT', '0 EX'], $this->values($xpath, '//fa:FaWiersz/fa:P_12'));
    }

    public function test_frozen_wdt_emits_taxpayer_prefix_before_seller_identity(): void
    {
        $this->settings()->forceFill(['zero_vat_classification' => KsefZeroVatClassification::Wdt])->save();
        $invoice = $this->issueInvoice(
            ['billing_country_code' => 'DE', 'billing_tax_id' => 'DE123456789'],
            [$this->grossItem('WDT', '100.00', '0.00')],
        );
        $this->settings()->forceFill(['zero_vat_classification' => KsefZeroVatClassification::Export])->save();

        $xpath = $this->xpath($this->generate($invoice)->xml);

        $this->assertSame('PL', $this->value($xpath, '/fa:Faktura/fa:Podmiot1/fa:PrefiksPodatnika'));
        $this->assertSame(
            'PrefiksPodatnika',
            $xpath->query('/fa:Faktura/fa:Podmiot1/*')->item(0)?->localName,
        );
        $this->assertSame('DE', $this->value($xpath, '/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:KodUE'));
        $this->assertSame('123456789', $this->value($xpath, '/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NrVatUE'));
        $this->assertSame('0 WDT', $this->value($xpath, '//fa:FaWiersz/fa:P_12'));
        $this->assertSame('100.00', $this->value($xpath, '//fa:Fa/fa:P_13_6_2'));
        $this->assertSame(0, $xpath->query('//fa:Fa/fa:P_13_6_3')->length);
    }

    public function test_export_without_wdt_does_not_emit_taxpayer_prefix(): void
    {
        $this->settings()->forceFill(['zero_vat_classification' => KsefZeroVatClassification::Export])->save();
        $invoice = $this->issueInvoice(
            ['billing_country_code' => 'DE', 'billing_tax_id' => 'DE123456789'],
            [$this->grossItem('Eksport', '100.00', '0.00')],
        );

        $xpath = $this->xpath($this->generate($invoice)->xml);

        $this->assertSame(0, $xpath->query('/fa:Faktura/fa:Podmiot1/fa:PrefiksPodatnika')->length);
        $this->assertSame('0 EX', $this->value($xpath, '//fa:FaWiersz/fa:P_12'));
        $this->assertSame('100.00', $this->value($xpath, '//fa:Fa/fa:P_13_6_3'));
    }

    public function test_frozen_mpp_and_buyer_identity_variants_are_mapped_without_current_setting_drift(): void
    {
        $settings = $this->settings();
        $settings->forceFill(['default_split_payment' => false])->save();
        $polish = $this->issueInvoice(['billing_tax_id' => '5260250995']);
        $settings->forceFill(['default_split_payment' => true])->save();
        $this->assertSame('2', $this->value($this->xpath($this->generate($polish)->xml), '//fa:Adnotacje/fa:P_18A'));

        $greek = $this->issueInvoice([
            'billing_country_code' => 'GR',
            'billing_tax_id' => 'EL123456789',
        ]);
        $greekXpath = $this->xpath($this->generate($greek)->xml);
        $this->assertSame('EL', $this->value($greekXpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:KodUE'));
        $this->assertSame('GR', $this->value($greekXpath, '//fa:Podmiot2/fa:Adres/fa:KodKraju'));
        $this->assertSame('1', $this->value($greekXpath, '//fa:Adnotacje/fa:P_18A'));

        $settings->forceFill(['send_without_buyer_nip' => true])->save();
        $noId = $this->issueInvoice(['billing_tax_id' => null]);
        $noIdXpath = $this->xpath($this->generate($noId)->xml);
        $this->assertSame('1', $this->value($noIdXpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:BrakID'));
        $this->assertSame('Kowalski Handel', $this->value($noIdXpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa'));
        $this->assertSame('PL', $this->value($noIdXpath, '//fa:Podmiot2/fa:Adres/fa:KodKraju'));
        $this->assertSame('Fakturowa 10/2', $this->value($noIdXpath, '//fa:Podmiot2/fa:Adres/fa:AdresL1'));
    }

    public function test_ordinary_buyer_requires_name_country_and_address_before_xml_building(): void
    {
        foreach ([
            'name' => ['name' => null, 'company_name' => null],
            'address' => ['street' => null, 'building_number' => null, 'apartment_number' => null],
            'country' => ['country_code' => null],
        ] as $changes) {
            $invoice = $this->issueInvoice();
            $snapshot = array_replace($invoice->buyer_snapshot, $changes);
            $invoice->forceFill(['buyer_snapshot' => $snapshot])->saveQuietly();

            $this->expectDomainError(
                'ksef_fa3_buyer_incomplete',
                fn () => $this->generate($invoice->refresh()),
            );
        }
    }

    public function test_missing_settings_fail_without_creating_configuration(): void
    {
        $invoice = $this->issueInvoice();
        $before = $invoice->fresh()->getAttributes();
        KsefSetting::query()->delete();

        $this->expectDomainError('ksef_configuration_missing', fn () => $this->generate($invoice));

        $this->assertSame(0, KsefSetting::query()->count());
        $this->assertSame($before, $invoice->fresh()->getAttributes());
    }

    public function test_foreign_invoice_uses_persisted_pln_vat_and_performs_no_http_during_generation(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'nbp_table' => 'A']);
        Http::fake(function (Request $request) {
            $this->assertStringContainsString('/A/EUR/', $request->url());

            return Http::response($this->nbpXml('EUR', '4.3420'));
        });
        $invoice = $this->issueInvoice(
            ['currency' => 'EUR'],
            [$this->grossItem('EUR 23', '123.00', '23.00', 'EUR')],
        );
        $expectedPlnVat = $invoice->tax_metadata_snapshot['converted_tax_summary']['groups'][0]['vat'];
        Http::assertSentCount(1);

        $xpath = $this->xpath($this->generate($invoice)->xml);

        $this->assertSame('EUR', $this->value($xpath, '//fa:Fa/fa:KodWaluty'));
        $this->assertSame('100.00', $this->value($xpath, '//fa:Fa/fa:P_13_1'));
        $this->assertSame('23.00', $this->value($xpath, '//fa:Fa/fa:P_14_1'));
        $this->assertSame($expectedPlnVat, $this->value($xpath, '//fa:Fa/fa:P_14_1W'));
        Http::assertSentCount(1);

        $metadata = $invoice->tax_metadata_snapshot;
        unset($metadata['converted_tax_summary']);
        $invoice->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $this->expectDomainError('ksef_fa3_currency_snapshot_invalid', fn () => $this->generate($invoice->refresh()));
        Http::assertSentCount(1);
    }

    public function test_preflight_and_authoritative_modes_share_xml_but_apply_different_finalization_gate(): void
    {
        $invoice = $this->issueInvoice();
        $generatedAt = CarbonImmutable::parse('2026-08-14T12:00:00Z');
        $preflight = $this->generate($invoice, $generatedAt, KsefFa3EligibilityMode::Preflight);
        $this->expectDomainError(
            'ksef_fa3_document_not_finalized',
            fn () => $this->generate($invoice, $generatedAt, KsefFa3EligibilityMode::Authoritative),
        );

        $invoice = app(InvoiceFinalizationService::class)->finalize($invoice);
        $authoritative = $this->generate($invoice, $generatedAt, KsefFa3EligibilityMode::Authoritative);

        $this->assertSame($preflight->xml, $authoritative->xml);
        $later = $this->generate($invoice, $generatedAt->addSecond(), KsefFa3EligibilityMode::Authoritative);
        $this->assertNotSame($authoritative->xml, $later->xml);
    }

    public function test_generation_rejects_corrupt_snapshots_and_invalid_footer_without_backfill(): void
    {
        $missingTax = $this->issueInvoice();
        $metadata = $missingTax->tax_metadata_snapshot;
        unset($metadata['ksef_tax']);
        $missingTax->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $before = $missingTax->fresh()->getAttributes();
        $this->expectDomainError('ksef_fa3_tax_snapshot_missing', fn () => $this->generate($missingTax->fresh()));
        $this->assertSame($before, $missingTax->fresh()->getAttributes());

        $invalidBdo = $this->issueInvoice(series: ['seller_bdo' => '1234567890']);
        $this->expectDomainError('ksef_fa3_schema_validation_failed', fn () => $this->generate($invalidBdo));

        $withoutFooter = $this->issueInvoice(series: ['seller_regon' => null, 'seller_bdo' => null]);
        $xpath = $this->xpath($this->generate($withoutFooter)->xml);
        $this->assertSame(0, $xpath->query('/fa:Faktura/fa:Stopka')->length);
    }

    public function test_current_order_series_and_content_settings_do_not_change_generated_xml(): void
    {
        $invoice = $this->issueInvoice();
        $generatedAt = CarbonImmutable::parse('2026-08-14T12:00:00Z');
        $before = $this->generate($invoice, $generatedAt)->xml;

        $invoice->order()->update(['billing_company_name' => 'NOWA NAZWA LIVE']);
        $invoice->series()->update(['seller_name' => 'NOWY SPRZEDAWCA LIVE', 'seller_bdo' => '999999999']);
        $this->settings()->forceFill([
            'zero_vat_classification' => KsefZeroVatClassification::Export,
            'default_split_payment' => true,
        ])->save();

        $this->assertSame($before, $this->generate($invoice->fresh(), $generatedAt)->xml);
    }

    private function issueInvoice(array $order = [], array $items = [[]], array $series = []): Invoice
    {
        $orderModel = $this->createDocumentOrder(array_merge([
            'external_id' => 'FA3-XML-'.uniqid(),
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

    private function generate(
        Invoice $invoice,
        ?CarbonImmutable $generatedAt = null,
        KsefFa3EligibilityMode $mode = KsefFa3EligibilityMode::Preflight,
    ): KsefFa3GeneratedDocument {
        return app(KsefFa3DocumentGenerator::class)->generate(
            $invoice,
            $generatedAt ?? CarbonImmutable::parse('2026-08-14T12:00:00Z'),
            $mode,
        );
    }

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml, LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fa', KsefFa3XmlBuilder::NAMESPACE);

        return $xpath;
    }

    private function value(DOMXPath $xpath, string $expression): string
    {
        return trim((string) $xpath->evaluate('string('.$expression.')'));
    }

    /** @return array<int, string> */
    private function values(DOMXPath $xpath, string $expression): array
    {
        return array_map(
            static fn ($node): string => trim($node->textContent),
            iterator_to_array($xpath->query($expression)),
        );
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

    private function settings()
    {
        return app(KsefSettingsService::class)->get()->refresh();
    }

    private function expectDomainError(string $code, callable $operation): InvoiceDomainException
    {
        try {
            $operation();
            $this->fail('Oczekiwano kontrolowanego błędu domenowego '.$code.'.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());

            return $exception;
        }
    }

    private function nbpXml(string $currency, string $mid): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<ExchangeRatesSeries><Table>A</Table><Code>'.$currency.'</Code><Rates><Rate>'
            .'<No>137/A/NBP/2026</No><EffectiveDate>2026-07-17</EffectiveDate><Mid>'.$mid.'</Mid>'
            .'</Rate></Rates></ExchangeRatesSeries>';
    }
}
