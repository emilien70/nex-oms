<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefZeroVatClassification;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\Ksef\CreatesKsefFa3CorrectionScenarios;
use Tests\TestCase;

class KsefFa3CorrectionDocumentGeneratorTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use CreatesKsefFa3CorrectionScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nbp.retries' => 0, 'nbp.retry_delay_ms' => 0]);
        Http::preventStrayRequests();
    }

    public function test_it_generates_a_deterministic_read_only_schema_valid_kor_for_ksef_root(): void
    {
        $this->ksefSettings();
        $root = $this->issueKsefRoot();
        $rootSubmission = $this->acceptKsefDocument($root);
        $correction = $this->issueKsefFinancialCorrection($root, 2);
        $before = $correction->fresh()->getAttributes();
        $itemsBefore = $correction->items()->orderBy('position')->get()
            ->map(fn ($item): array => $item->getAttributes())->all();
        $settingsBefore = KsefSetting::query()->firstOrFail()->getAttributes();
        $submissionCount = KsefInvoiceSubmission::query()->count();
        $credentialCount = KsefCredential::query()->count();

        $first = $this->generateKsefCorrection($correction);
        $second = $this->generateKsefCorrection($correction);
        $xpath = $this->ksefXpath($first->xml);

        $this->assertSame($first->xml, $second->xml);
        $this->assertSame('2026-08-30T10:34:56Z', $first->generatedAt);
        $this->assertSame('FA (3) 1-0E', $first->schemaId);
        $this->assertSame('KOR', $this->ksefValue($xpath, '//fa:Fa/fa:RodzajFaktury'));
        $this->assertNotSame('', $this->ksefValue($xpath, '//fa:Fa/fa:PrzyczynaKorekty'));
        $this->assertSame(0, $xpath->query('//fa:Fa/fa:TypKorekty')->length);
        $this->assertSame($root->issue_date->toDateString(), $this->ksefValue($xpath, '//fa:DaneFaKorygowanej/fa:DataWystFaKorygowanej'));
        $this->assertSame($root->number, $this->ksefValue($xpath, '//fa:DaneFaKorygowanej/fa:NrFaKorygowanej'));
        $this->assertSame('1', $this->ksefValue($xpath, '//fa:DaneFaKorygowanej/fa:NrKSeF'));
        $this->assertSame($rootSubmission->ksef_number, $this->ksefValue($xpath, '//fa:DaneFaKorygowanej/fa:NrKSeFFaKorygowanej'));
        $this->assertSame(0, $xpath->query('//fa:DaneFaKorygowanej/fa:NrKSeFN')->length);
        $this->assertSame('100.00', $this->ksefValue($xpath, '//fa:Fa/fa:P_13_1'));
        $this->assertSame('23.00', $this->ksefValue($xpath, '//fa:Fa/fa:P_14_1'));
        $this->assertSame('123.00', $this->ksefValue($xpath, '//fa:Fa/fa:P_15'));
        $this->assertSame(2, $xpath->query('//fa:Fa/fa:FaWiersz')->length);
        $this->assertSame('1', $this->ksefValue($xpath, '(//fa:FaWiersz)[1]/fa:StanPrzed'));
        $this->assertSame(0, $xpath->query('(//fa:FaWiersz)[2]/fa:StanPrzed')->length);
        $this->assertSame(0, $xpath->query('//fa:Podmiot2K|//fa:IDNabywcy')->length);
        $this->assertSame($before, $correction->fresh()->getAttributes());
        $this->assertSame(
            $itemsBefore,
            $correction->items()->orderBy('position')->get()
                ->map(fn ($item): array => $item->getAttributes())->all(),
        );
        $this->assertSame($settingsBefore, KsefSetting::query()->firstOrFail()->getAttributes());
        $this->assertSame($submissionCount, KsefInvoiceSubmission::query()->count());
        $this->assertSame($credentialCount, KsefCredential::query()->count());
        Http::assertNothingSent();
    }

    public function test_explicit_outside_root_uses_only_nr_ksef_n(): void
    {
        $this->ksefSettings();
        $root = $this->issueKsefRoot();
        $this->markKsefOutside($root, KsefEnvironment::Production);
        $correction = $this->issueKsefFinancialCorrection($root);

        $xpath = $this->ksefXpath($this->generateKsefCorrection($correction)->xml);

        $this->assertSame(1, $xpath->query('//fa:DaneFaKorygowanej/fa:NrKSeFN')->length);
        $this->assertSame(0, $xpath->query('//fa:DaneFaKorygowanej/fa:NrKSeF')->length);
        $this->assertSame(0, $xpath->query('//fa:DaneFaKorygowanej/fa:NrKSeFFaKorygowanej')->length);
        Http::assertNothingSent();
    }

    public function test_production_does_not_fall_back_to_demo_source_reference(): void
    {
        $this->ksefSettings(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Demo);
        $correction = $this->issueKsefFinancialCorrection($root);

        $this->expectDomainError(
            'ksef_fa3_correction_source_ksef_environment_mismatch',
            fn () => $this->generateKsefCorrection($correction),
        );
    }

    public function test_second_and_third_corrections_each_reference_exactly_the_root_invoice(): void
    {
        $this->ksefSettings();
        $root = $this->issueKsefRoot();
        $rootSubmission = $this->acceptKsefDocument($root);
        $first = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root, 2));
        $this->acceptKsefDocument($first);
        $second = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root, 3));
        $this->acceptKsefDocument($second);
        $third = $this->issueKsefFinancialCorrection($root, 4);

        foreach ([$second, $third] as $correction) {
            $xpath = $this->ksefXpath($this->generateKsefCorrection($correction)->xml);
            $this->assertSame(1, $xpath->query('//fa:DaneFaKorygowanej')->length);
            $this->assertSame($root->number, $this->ksefValue($xpath, '//fa:DaneFaKorygowanej/fa:NrFaKorygowanej'));
            $this->assertSame($rootSubmission->ksef_number, $this->ksefValue($xpath, '//fa:DaneFaKorygowanej/fa:NrKSeFFaKorygowanej'));
            $this->assertNotSame($correction->previousCorrection?->number, $this->ksefValue($xpath, '//fa:DaneFaKorygowanej/fa:NrFaKorygowanej'));
        }
    }

    public function test_buyer_before_after_are_linked_and_buyer_only_correction_has_no_lines(): void
    {
        $this->ksefSettings();
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root);
        $after = array_replace($root->buyer_snapshot, [
            'company_name' => 'Nabywca Żółć sp. z o.o.',
            'street' => 'Nowa ulica',
        ]);
        $correction = $this->issueKsefBuyerCorrection($root, $after);

        $xpath = $this->ksefXpath($this->generateKsefCorrection($correction)->xml);

        $this->assertSame('Nabywca Żółć sp. z o.o.', $this->ksefValue($xpath, '/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa'));
        $this->assertSame('Nowa ulica 10/2', $this->ksefValue($xpath, '/fa:Faktura/fa:Podmiot2/fa:Adres/fa:AdresL1'));
        $this->assertSame('Kowalski Handel', $this->ksefValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:DaneIdentyfikacyjne/fa:Nazwa'));
        $this->assertSame('Fakturowa 10/2', $this->ksefValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:Adres/fa:AdresL1'));
        $this->assertSame('NB/01', $this->ksefValue($xpath, '/fa:Faktura/fa:Podmiot2/fa:IDNabywcy'));
        $this->assertSame('NB/01', $this->ksefValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:IDNabywcy'));
        $this->assertSame('0.00', $this->ksefValue($xpath, '//fa:Fa/fa:P_15'));
        $this->assertSame(0, $xpath->query('//fa:Fa/fa:FaWiersz')->length);
    }

    public function test_buyer_tax_identity_change_fails_before_xml_generation(): void
    {
        $this->ksefSettings();
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root);
        $correction = $this->issueKsefBuyerCorrection(
            $root,
            array_replace($root->buyer_snapshot, ['tax_id' => '5210080410']),
        );

        $this->expectDomainError(
            'ksef_fa3_correction_buyer_identity_change_not_supported',
            fn () => $this->generateKsefCorrection($correction),
        );
    }

    #[DataProvider('zeroVatCases')]
    public function test_historical_zero_vat_semantics_are_used_in_rows_and_difference_bucket(
        KsefZeroVatClassification $classification,
        string $expectedRate,
        string $expectedElement,
    ): void {
        $settings = $this->ksefSettings(zeroClassification: $classification);
        $buyer = $classification === KsefZeroVatClassification::Wdt ? [
            'billing_company_name' => 'Muster GmbH',
            'billing_tax_id' => 'DE123456789',
            'billing_street' => 'Musterstrasse',
            'billing_building_number' => '5',
            'billing_apartment_number' => null,
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
            'billing_country_code' => 'DE',
        ] : [];
        $root = $this->issueKsefRoot(
            [['unit_price_gross' => '100.00', 'vat_rate' => '0.00']],
            $buyer,
        );
        $this->acceptKsefDocument($root);
        $settings->forceFill([
            'zero_vat_classification' => $classification === KsefZeroVatClassification::Export
                ? KsefZeroVatClassification::Domestic
                : KsefZeroVatClassification::Export,
        ])->save();
        $correction = $this->issueKsefFinancialCorrection($root, 2);

        $xpath = $this->ksefXpath($this->generateKsefCorrection($correction)->xml);

        $this->assertSame($expectedRate, $this->ksefValue($xpath, '(//fa:FaWiersz)[1]/fa:P_12'));
        $this->assertSame($expectedRate, $this->ksefValue($xpath, '(//fa:FaWiersz)[2]/fa:P_12'));
        $this->assertSame('100.00', $this->ksefValue($xpath, '//fa:Fa/fa:'.$expectedElement));
        foreach (array_diff(['P_13_6_1', 'P_13_6_2', 'P_13_6_3'], [$expectedElement]) as $absent) {
            $this->assertSame(0, $xpath->query('//fa:Fa/fa:'.$absent)->length);
        }
    }

    public static function zeroVatCases(): array
    {
        return [
            'WDT' => [KsefZeroVatClassification::Wdt, '0 WDT', 'P_13_6_2'],
            'export' => [KsefZeroVatClassification::Export, '0 EX', 'P_13_6_3'],
            'domestic' => [KsefZeroVatClassification::Domestic, '0 KR', 'P_13_6_1'],
        ];
    }

    public function test_vat_change_uses_negative_and_positive_semantic_buckets(): void
    {
        $this->ksefSettings();
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root);
        $items = $this->submittedKsefItems($root);
        $items[0]['unit_price_gross'] = '108.00';
        $items[0]['vat_rate'] = '8';
        $correction = $this->issueKsefCorrection($root, $items);

        $xpath = $this->ksefXpath($this->generateKsefCorrection($correction)->xml);

        $this->assertSame('23', $this->ksefValue($xpath, '(//fa:FaWiersz)[1]/fa:P_12'));
        $this->assertSame('8', $this->ksefValue($xpath, '(//fa:FaWiersz)[2]/fa:P_12'));
        $this->assertSame('-100.00', $this->ksefValue($xpath, '//fa:Fa/fa:P_13_1'));
        $this->assertSame('-23.00', $this->ksefValue($xpath, '//fa:Fa/fa:P_14_1'));
        $this->assertSame('100.00', $this->ksefValue($xpath, '//fa:Fa/fa:P_13_2'));
        $this->assertSame('8.00', $this->ksefValue($xpath, '//fa:Fa/fa:P_14_2'));
        $this->assertSame('-15.00', $this->ksefValue($xpath, '//fa:Fa/fa:P_15'));
    }

    public function test_price_change_emits_old_and_new_unit_and_line_net_values(): void
    {
        $this->ksefSettings();
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root);
        $items = $this->submittedKsefItems($root);
        $items[0]['unit_price_gross'] = '246.00';
        $correction = $this->issueKsefCorrection($root, $items);

        $xpath = $this->ksefXpath($this->generateKsefCorrection($correction)->xml);

        $this->assertSame('100.00', $this->ksefValue($xpath, '(//fa:FaWiersz)[1]/fa:P_9A'));
        $this->assertSame('100.00', $this->ksefValue($xpath, '(//fa:FaWiersz)[1]/fa:P_11'));
        $this->assertSame('200.00', $this->ksefValue($xpath, '(//fa:FaWiersz)[2]/fa:P_9A'));
        $this->assertSame('200.00', $this->ksefValue($xpath, '(//fa:FaWiersz)[2]/fa:P_11'));
        $this->assertSame('123.00', $this->ksefValue($xpath, '//fa:Fa/fa:P_15'));
    }

    public function test_added_removed_and_unchanged_lines_use_uniform_pairs_and_deterministic_order(): void
    {
        $this->ksefSettings();
        $root = $this->issueKsefRoot([
            ['unit_price_gross' => '123.00', 'vat_rate' => '23.00'],
            ['unit_price_gross' => '123.00', 'vat_rate' => '23.00'],
            ['unit_price_gross' => '123.00', 'vat_rate' => '23.00'],
        ]);
        $this->acceptKsefDocument($root);
        $items = $this->submittedKsefItems($root);
        $items[0]['quantity'] = 0;
        $items[2]['quantity'] = 2;
        $items[] = $this->addedKsefItem(4);
        $correction = $this->issueKsefCorrection($root, $items);

        $xpath = $this->ksefXpath($this->generateKsefCorrection($correction)->xml);

        $this->assertSame(4, count(data_get($correction->tax_metadata_snapshot, 'ksef_correction.line_treatments')));
        $this->assertSame(6, $xpath->query('//fa:Fa/fa:FaWiersz')->length);
        $this->assertSame(
            ['1', '1', '3', '3', '4', '4'],
            array_map(
                fn (int $index): string => $this->ksefValue($xpath, '(//fa:FaWiersz)['.$index.']/fa:NrWierszaFa'),
                range(1, 6),
            ),
        );
        $this->assertSame('0', $this->ksefValue($xpath, '(//fa:FaWiersz)[2]/fa:P_8B'));
        $this->assertSame('0', $this->ksefValue($xpath, '(//fa:FaWiersz)[5]/fa:P_8B'));
        $this->assertSame('1', $this->ksefValue($xpath, '(//fa:FaWiersz)[6]/fa:P_8B'));
        foreach ([1, 3, 5] as $index) {
            $this->assertSame('1', $this->ksefValue($xpath, '(//fa:FaWiersz)['.$index.']/fa:StanPrzed'));
        }
    }

    public function test_foreign_monetary_uses_frozen_difference_vat_and_buyer_only_needs_no_conversion(): void
    {
        $this->ksefSettings();
        $root = $this->makeKsefForeign($this->issueKsefRoot());
        $this->acceptKsefDocument($root);
        $monetary = $this->issueKsefFinancialCorrection($root, 2);
        $expectedPlnVat = data_get($monetary->tax_metadata_snapshot, 'converted_tax_summary.groups.0.vat');

        $xpath = $this->ksefXpath($this->generateKsefCorrection($monetary)->xml);
        $this->assertSame('EUR', $this->ksefValue($xpath, '//fa:Fa/fa:KodWaluty'));
        $this->assertSame($expectedPlnVat, $this->ksefValue($xpath, '//fa:Fa/fa:P_14_1W'));

        $buyerRoot = $this->issueKsefRoot();
        $buyerRoot->forceFill(['currency' => 'EUR'])->saveQuietly();
        $this->acceptKsefDocument($buyerRoot);
        $buyerOnly = $this->issueKsefBuyerCorrection(
            $buyerRoot,
            array_replace($buyerRoot->buyer_snapshot, ['street' => 'Walutowa zmiana']),
        );
        $buyerXpath = $this->ksefXpath($this->generateKsefCorrection($buyerOnly)->xml);
        $this->assertSame('EUR', $this->ksefValue($buyerXpath, '//fa:Fa/fa:KodWaluty'));
        $this->assertSame('0.00', $this->ksefValue($buyerXpath, '//fa:Fa/fa:P_15'));
        $this->assertSame(0, $buyerXpath->query('//fa:P_14_1W|//fa:P_14_2W|//fa:P_14_3W')->length);
        Http::assertNothingSent();
    }

    public function test_root_annotation_snapshot_is_preserved_after_current_setting_changes(): void
    {
        $settings = $this->ksefSettings();
        $settings->forceFill(['default_split_payment' => true])->save();
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root);
        $settings->forceFill(['default_split_payment' => false])->save();
        $correction = $this->issueKsefFinancialCorrection($root);

        $xpath = $this->ksefXpath($this->generateKsefCorrection($correction)->xml);

        $this->assertTrue(data_get($root->tax_metadata_snapshot, 'ksef_tax.annotations.split_payment'));
        $this->assertFalse((bool) $settings->fresh()->default_split_payment);
        $this->assertSame('1', $this->ksefValue($xpath, '//fa:Adnotacje/fa:P_18A'));
    }

    private function expectDomainError(string $code, callable $operation): InvoiceDomainException
    {
        try {
            $operation();
            $this->fail('Expected domain error '.$code.'.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());

            return $exception;
        }
    }
}
