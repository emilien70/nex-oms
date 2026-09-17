<?php

namespace Tests\Feature\Invoices;

use App\Models\OrderStatusSetting;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionTotalsCalculator;
use Modules\Invoices\Services\InvoiceTotalsCalculator;
use Modules\Invoices\Services\JpkV7m3Exporter;
use Modules\Invoices\Services\JpkV7m3SchemaValidator;
use Modules\Ksef\Enums\KsefZeroVatClassification;
use Modules\Ksef\Services\KsefFa3TaxTreatmentResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesRegisterJpkTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Http::fake();
        Bus::fake();
        OrderStatusSetting::orderedSettings();
        $this->travelTo(now()->setDate(2026, 9, 17)->setTime(12, 30));
    }

    public function test_real_endpoint_validates_official_schema_and_escapes_historical_data(): void
    {
        $name = 'Żółć & Syn <Firma> "A"';
        $invoice = $this->invoice(['buyer_name_snapshot' => $name, 'buyer_snapshot' => ['name' => $name, 'tax_id' => null]]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $xml = $this->export($this->payload([$invoice->id]));
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        $xp = $this->xpath($xml);
        foreach (['//j:KodFormularza' => 'JPK_VAT', '//j:WariantFormularza' => '3', '//j:Rok' => '2026', '//j:Miesiac' => '9',
            '//j:NazwaSystemu' => 'NEX-OMS', '//j:KodUrzedu' => '0202', '//j:CelZlozenia' => '1', '//j:NrKontrahenta' => 'BRAK',
            '//j:NazwaKontrahenta' => $name, '//j:DowodSprzedazy' => 'JPK 1', '//j:K_19' => '100.00', '//j:K_20' => '23.00',
            '//j:BFK' => '1', '//j:LiczbaWierszySprzedazy' => '1', '//j:PodatekNalezny' => '23.00',
            '//j:LiczbaWierszyZakupow' => '0', '//j:PodatekNaliczony' => '0.00', '//j:OsobaNiefizyczna/j:NIP' => '1234563218'] as $path => $expected) {
            $this->assertSame($expected, $xp->evaluate('string('.$path.')'));
        }
        $this->assertSame('JPK_V7M (3)', $xp->evaluate('string(//j:KodFormularza/@kodSystemowy)'));
        $this->assertSame('1-0E', $xp->evaluate('string(//j:KodFormularza/@wersjaSchemy)'));
        $this->assertSame(now()->utc()->format('Y-m-d\TH:i:s\Z'), $xp->evaluate('string(//j:DataWytworzeniaJPK)'));
        $this->assertSame(0, $xp->query('//j:Deklaracja | //j:ZakupWiersz | //j:TypDokumentu | //comment() | //*[namespace-uri()=""]')->length);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_person_taxpayer_and_explicit_later_period(): void
    {
        $invoice = $this->invoice(['issue_date' => '2026-02-01', 'sale_date' => '2026-01-30']);
        $xml = $this->export(array_replace($this->payload([$invoice->id]), [
            'jpk_type' => 'person', 'jpk_first_name' => 'Jan', 'jpk_last_name' => 'Testowy', 'jpk_birth_date' => '1980-01-01', 'jpk_phone' => '123456789',
        ]));
        $xp = $this->xpath($xml);
        $this->assertSame('1980-01-01', $xp->evaluate('string(//e:DataUrodzenia)'));
        $this->assertSame('2026-02-01', $xp->evaluate('string(//j:DataWystawienia)'));
        $this->assertSame('9', $xp->evaluate('string(//j:Miesiac)'));
        $this->assertSame(1, $xp->query('//j:OsobaFizyczna')->length);
    }

    #[DataProvider('invalidContexts')]
    public function test_invalid_taxpayer_or_period_is_not_fabricated(array $changes): void
    {
        $payload = array_replace($this->payload([$this->invoice()->id]), $changes);
        $this->post(route('invoices.sales-register.export'), $payload)->assertStatus(422)->assertHeaderMissing('Content-Disposition');
    }

    public static function invalidContexts(): array
    {
        return array_map(static fn ($changes) => [$changes], [['jpk_month' => '1'], ['jpk_year' => '2025'], ['jpk_month' => ''], ['jpk_type' => ''], ['jpk_email' => ''],
            ['jpk_type' => 'person'], ['jpk_type' => 'person', 'jpk_first_name' => 'A', 'jpk_last_name' => 'B', 'jpk_birth_date' => '1980-02-30'],
            ['jpk_nip' => '1234563219'], ['jpk_purpose' => '3']]);
    }

    public function test_empty_period_is_xsd_valid_and_not_a_fake_document(): void
    {
        $payload = $this->period();
        $xml = $this->export($payload);
        $xp = $this->xpath($xml);
        $this->assertSame(0, $xp->query('//j:SprzedazWiersz')->length);
        $this->assertSame('0', $xp->evaluate('string(//j:LiczbaWierszySprzedazy)'));
        $this->assertSame('0.00', $xp->evaluate('string(//j:PodatekNalezny)'));
    }

    public function test_unknown_ksef_requires_exact_per_document_confirmation_and_no_override(): void
    {
        $invoice = $this->invoice();
        $payload = $this->payload([$invoice->id]);
        $payload['jpk_markers'] = [];
        $review = $this->post(route('invoices.sales-register.export'), $payload)->assertOk();
        $this->assertNotEmpty($review->viewData('jpkReview')['errors']);
        $this->assertSame([], $review->viewData('jpkReview')['documents'][0]['choice']);
        $this->post(route('invoices.sales-register.export'), $payload + [])->assertSee('Potwierdź sposób wystawienia');
        $this->post(route('invoices.sales-register.export'), array_replace($payload, ['jpk_markers' => [99999 => 'BFK']]))->assertStatus(422);
        $this->submission($invoice);
        $review = $this->post(route('invoices.sales-register.export'), $this->payload([$invoice->id]))->assertOk();
        $this->assertStringContainsString('nadpisywać', implode(' ', $review->viewData('jpkReview')['errors']));
    }

    public function test_download_requires_review_confirmation_and_unchanged_data(): void
    {
        $invoice = $this->invoice();
        $payload = $this->payload([$invoice->id]);
        $review = $this->post(route('invoices.sales-register.export'), $payload)->assertOk()->viewData('jpkReview');
        $download = array_replace($payload, ['jpk_action' => 'download', 'jpk_fingerprint' => $review['fingerprint']]);
        $this->post(route('invoices.sales-register.export'), $download)->assertStatus(422);
        $invoice->update(['number' => 'CHANGED']);
        $this->post(route('invoices.sales-register.export'), $download + ['jpk_confirm' => '1'])->assertStatus(422)->assertSee('Dane lub zakres eksportu zmieniły się');
    }

    public function test_multiple_rates_and_zero_classifications_use_one_row_and_gtu(): void
    {
        $rates = ['23.00', '22.00', '8.00', '7.00', '5.00', '0.00', '0.00', '0.00'];
        $states = array_map(fn ($rate) => $this->state($rate, match ($rate) {
            '23.00' => '123', '22.00' => '122', '8.00' => '108', '7.00' => '107', '5.00' => '105', default => '100'
        }), $rates);
        $invoice = $this->invoice([], $states, ['standard', 'standard', 'standard', 'standard', 'standard', 'domestic_zero', 'wdt', 'export']);
        $invoice->items()->first()->update(['gtu_codes' => ['GTU_06', 'GTU_01', 'GTU_06']]);
        $xp = $this->xpath($this->export($this->payload([$invoice->id])));
        foreach (['K_19' => '200.00', 'K_20' => '45.00', 'K_17' => '200.00', 'K_18' => '15.00', 'K_15' => '100.00', 'K_16' => '5.00',
            'K_13' => '100.00', 'K_21' => '100.00', 'K_22' => '100.00', 'GTU_01' => '1', 'GTU_06' => '1', 'PodatekNalezny' => '65.00'] as $field => $value) {
            $this->assertSame($value, $xp->evaluate('string(//j:'.$field.')'));
        }
        $this->assertSame(1, $xp->query('//j:SprzedazWiersz')->length);
    }

    #[DataProvider('corrections')]
    public function test_corrections_map_own_difference_once(string $before, string $after, string $expected): void
    {
        $invoice = $this->correction($this->state('23.00', $before), $this->state('23.00', $after));
        $xp = $this->xpath($this->export($this->payload([$invoice->id])));
        $this->assertSame($expected, $xp->evaluate('string(//j:K_19)'));
        $this->assertSame('1', $xp->evaluate('string(//j:LiczbaWierszySprzedazy)'));
        $this->assertSame('1', $xp->evaluate('string(//j:CelZlozenia)'));
    }

    public static function corrections(): array
    {
        return [['246', '123', '-100.00'], ['123', '246', '100.00'], ['123', '123', '0.00'], ['123', '0', '-100.00']];
    }

    public function test_zero_gross_correction_moves_between_categories_without_source_lookup(): void
    {
        $first = $this->correction($this->state('0.00', '100'), $this->state('0.00', '100'), 'wdt', 'export');
        $next = $this->correction($this->state('0.00', '100'), $this->state('0.00', '100'), 'export', 'domestic_zero');
        $next->update(['previous_correction_id' => $first->id]);
        $xp = $this->xpath($this->export($this->payload([$next->id])));
        $this->assertSame('-100.00', $xp->evaluate('string(//j:K_22)'));
        $this->assertSame('100.00', $xp->evaluate('string(//j:K_13)'));
        $this->assertSame('0.00', $xp->evaluate('string(//j:PodatekNalezny)'));
        $this->assertSame(1, $xp->query('//j:SprzedazWiersz')->length);
    }

    public function test_production_number_mandatory_even_when_checkbox_is_false_and_demo_is_not_evidence(): void
    {
        $invoice = $this->invoice();
        $this->submission($invoice);
        $this->submission($invoice, ['environment' => 'demo', 'ksef_number' => '1234563218-20260901-000000000002-CB']);
        $payload = $this->payload([$invoice->id]);
        $payload['jpk_markers'] = [];
        $xp = $this->xpath($this->export($payload));
        $this->assertSame('1234563218-20260901-000000000001-CA', $xp->evaluate('string(//j:NrKSeF)'));
        $this->assertSame(0, $xp->query('//j:OFF | //j:BFK | //j:DI')->length);
        DB::table('ksef_invoice_submissions')->where('environment', 'production')->delete();
        $review = $this->post(route('invoices.sales-register.export'), $payload)->assertOk()->viewData('jpkReview');
        $this->assertNotEmpty($review['errors']);
        $this->assertTrue($review['documents'][0]['manual']);
    }

    public function test_control_formula_excludes_fp_and_subtracts_k360_without_counting_subsets(): void
    {
        $this->assertSame('9.00', app(JpkV7m3Exporter::class)->controlTax([
            ['K_16' => '1.00', 'K_18' => '2.00', 'K_20' => '10.00', 'K_36' => '1.00', 'K_360' => '3.00', 'K_12' => '900.00'],
            ['TypDokumentu' => 'FP', 'K_20' => '999.00'],
        ]));
    }

    public function test_foreign_currency_uses_saved_pln_without_exchange_lookup_or_shipping_requirement(): void
    {
        $invoice = $this->invoice(['currency' => 'EUR'], [$this->state('0.00', '100')], ['wdt']);
        $this->conversion($invoice, '0.00', '400.00', '0.00', '400.00');
        $xp = $this->xpath($this->export($this->payload([$invoice->id])));
        $this->assertSame('400.00', $xp->evaluate('string(//j:K_21)'));
        Http::assertNothingSent();
    }

    public function test_foreign_merged_zero_categories_block_instead_of_proportional_split(): void
    {
        $invoice = $this->invoice(['currency' => 'EUR'], [$this->state('0.00', '50'), $this->state('0.00', '50')], ['wdt', 'export']);
        $this->conversion($invoice, '0.00', '400.00', '0.00', '400.00');
        $this->blocked($this->payload([$invoice->id]), 'nie rozdziela');
    }

    #[DataProvider('incompleteDocuments')]
    public function test_incomplete_or_special_document_blocks_entire_selection(string $case): void
    {
        $valid = $this->invoice();
        $bad = $this->invoice([], $case === 'zero without semantics' ? [$this->state('0.00', '100')] : null);
        $metadata = $bad->tax_metadata_snapshot;
        match ($case) {
            'missing semantics' => $bad->update(['tax_metadata_snapshot' => []]),
            'zero without semantics' => $bad->update(['currency' => 'EUR', 'tax_metadata_snapshot' => []]),
            'missing pln' => $bad->update(['currency' => 'EUR']),
            'wrong seller' => $bad->update(['seller_tax_id_snapshot' => '1111111111']),
            'FP' => $bad->update(['tax_metadata_snapshot' => $metadata + ['document_type' => 'FP']]),
            'OSS' => $bad->update(['tax_metadata_snapshot' => $metadata + ['oss' => true]]),
            'bad GTU' => $bad->items()->first()->update(['gtu_codes' => ['GTU_99']]),
            'wrong item binding' => $bad->update(['tax_metadata_snapshot' => ['ksef_tax' => array_replace($metadata['ksef_tax'], ['line_treatments' => []])]]),
            'buyer conflict' => $bad->update(['buyer_tax_id_snapshot' => '1234563218']),
            'buyer array' => $bad->update(['buyer_snapshot' => ['name' => ['broken'], 'tax_id' => null]]),
            'xml control' => $bad->update(['number' => "JPK\x01INVALID"]),
            'invalid utf8' => DB::table('invoices')->where('id', $bad->id)->update(['number' => "JPK\xC3\x28"]),
            'too long' => $bad->update(['number' => str_repeat('A', 300)]),
        };
        $this->blocked($this->payload([$valid->id, $bad->id]));
    }

    public static function incompleteDocuments(): array
    {
        return array_map(static fn ($case) => [$case], ['missing semantics', 'zero without semantics', 'missing pln', 'wrong seller', 'FP', 'OSS', 'bad GTU', 'wrong item binding', 'buyer conflict', 'buyer array', 'xml control', 'invalid utf8', 'too long']);
    }

    #[DataProvider('buyerIdentities')]
    public function test_buyer_identifier_is_text_with_its_own_tax_country(string $raw, array $identity, string $country, string $number): void
    {
        $invoice = $this->invoice(['buyer_tax_id_snapshot' => $raw, 'buyer_snapshot' => [
            'name' => 'Klient fikcyjny', 'tax_id' => $raw, 'country_code' => 'US', 'tax_identity' => ['version' => 1, 'status' => 'resolved'] + $identity,
        ]]);
        $xp = $this->xpath($this->export($this->payload([$invoice->id])));
        $this->assertSame($country, $xp->evaluate('string(//j:KodKrajuNadaniaTIN)'));
        $this->assertSame($number, $xp->evaluate('string(//j:NrKontrahenta)'));
    }

    public static function buyerIdentities(): array
    {
        return [['PL1234563218', ['type' => 'pl_nip', 'country_code' => 'PL', 'identifier' => '1234563218'], 'PL', '1234563218'],
            ['DE001234567', ['type' => 'eu_vat', 'country_code' => 'DE', 'identifier' => '001234567'], 'DE', '001234567'],
            ['ATU12345678', ['type' => 'eu_vat', 'country_code' => 'AT', 'identifier' => 'U12345678'], 'AT', 'U12345678']];
    }

    #[DataProvider('badBuyerIdentities')]
    public function test_ambiguous_buyer_identifier_is_not_changed_to_brak(string $raw, mixed $identity): void
    {
        $invoice = $this->invoice(['buyer_tax_id_snapshot' => $raw, 'buyer_snapshot' => ['name' => 'Klient fikcyjny', 'tax_id' => $raw, 'tax_identity' => $identity]]);
        $this->blocked($this->payload([$invoice->id]));
    }

    public static function badBuyerIdentities(): array
    {
        return [['1234563219', ['version' => 1, 'status' => 'resolved', 'type' => 'pl_nip', 'country_code' => 'PL', 'identifier' => '1234563219']],
            ['FR001234567', ['version' => 1, 'status' => 'resolved', 'type' => 'eu_vat', 'country_code' => 'DE', 'identifier' => '001234567']],
            ['XX123', null], ['1234563218', []]];
    }

    #[DataProvider('procedures')]
    public function test_persisted_production_procedure_selects_exactly_one_marker(string $procedure, string $marker): void
    {
        $invoice = $this->invoice();
        if ($procedure === 'outside_ksef') {
            DB::table('ksef_invoice_provenances')->insert(['invoice_id' => $invoice->id, 'environment' => 'production', 'provenance' => $procedure, 'recorded_at' => now()]);
        } else {
            $this->offline($invoice, $procedure);
        }
        $payload = $this->payload([$invoice->id]);
        $payload['jpk_markers'] = [];
        $xp = $this->xpath($this->export($payload));
        $this->assertSame('1', $xp->evaluate('string(//j:'.$marker.')'));
        $this->assertSame(1, $xp->query('//j:OFF | //j:BFK | //j:DI | //j:NrKSeF')->length);
    }

    public static function procedures(): array
    {
        return [['outside_ksef', 'BFK'], ['failure', 'OFF'], ['offline24', 'DI'], ['planned_unavailability', 'DI']];
    }

    #[DataProvider('conflictingTransmissions')]
    public function test_conflicting_transmission_cannot_be_hidden_by_manual_code(array $attributes, bool $second): void
    {
        $invoice = $this->invoice();
        $this->submission($invoice, $attributes);
        if ($second) {
            $this->submission($invoice, ['ksef_number' => '1234563218-20260901-000000000002-CB']);
        }
        $this->blocked($this->payload([$invoice->id]));
    }

    public static function conflictingTransmissions(): array
    {
        return [[['status' => 'rejected', 'ksef_number' => null], false], [['status' => 'pending', 'ksef_number' => null], false],
            [['seller_nip' => '1111111111'], false], [['invoice_hash' => 'bad'], false], [[], true]];
    }

    public function test_more_than_one_batch_preserves_selected_scope_and_has_bounded_query_count(): void
    {
        $ids = [];
        for ($i = 0; $i < 201; $i++) {
            $ids[] = $this->invoice()->id;
        }
        $excluded = $this->invoice(['issue_date' => '2026-10-01']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $xml = $this->export(array_replace($this->period(), ['jpk_markers' => array_fill_keys($ids, 'BFK')]));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(80, count($queries));
        $xp = $this->xpath($xml);
        $this->assertSame('201', $xp->evaluate('string(//j:LiczbaWierszySprzedazy)'));
        $this->assertSame('4623.00', $xp->evaluate('string(//j:PodatekNalezny)'));
        $this->assertSame(0, $xp->query('//j:DowodSprzedazy[text()="'.$excluded->number.'"]')->length);
    }

    public function test_official_xsd_rejects_missing_choice_order_namespace_and_value_and_blocks_xxe(): void
    {
        $xml = $this->export($this->payload([$this->invoice()->id]));
        $validator = app(JpkV7m3SchemaValidator::class);
        $mutations = [
            str_replace('<BFK>1</BFK>', '', $xml),
            str_replace('<BFK>1</BFK>', '<BFK>2</BFK>', $xml),
            str_replace('<BFK>1</BFK>', '<BFK>1</BFK><OFF>1</OFF>', $xml),
            str_replace('<K_19>100.00</K_19>', '<K_19>100,00</K_19>', $xml),
            str_replace('<K_19>100.00</K_19>', '<K_19 xmlns="">100.00</K_19>', $xml),
            str_replace('<NIP>1234563218</NIP>', '<etd:NIP>1234563218</etd:NIP>', $xml),
            str_replace('<K_19>100.00</K_19>', '<GTU_01>1</GTU_01><K_19>100.00</K_19>', str_replace('<K_20>23.00</K_20>', '<K_20>23.00</K_20><GTU_06>1</GTU_06>', $xml)),
            str_replace('<Email>test@example.test</Email>', '<Email/>', $xml),
            str_replace('<KodUrzedu>0202</KodUrzedu>', '<KodUrzedu>9999</KodUrzedu>', $xml),
            '<!DOCTYPE JPK [<!ENTITY leak SYSTEM "file:///C:/Windows/win.ini">]><JPK>&leak;</JPK>',
            "<JPK>\xC3\x28</JPK>",
        ];
        foreach ($mutations as $bad) {
            $this->assertNotEmpty($validator->errors($bad));
        }
        $withRemoteHint = str_replace('<JPK ', '<JPK xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="'.JpkV7m3SchemaValidator::NS.' https://invalid.example/secret.xsd" ', $xml);
        $this->assertSame([], $validator->errors($withRemoteHint));
        Http::assertNothingSent();
    }

    public function test_private_file_is_removed_when_response_creation_fails(): void
    {
        $before = glob(storage_path('app/private/sales-register-exports/jpk-*.xml'));
        $payload = $this->payload([$this->invoice()->id]);
        $review = $this->post(route('invoices.sales-register.export'), $payload)->assertOk()->viewData('jpkReview');
        $factory = app(ResponseFactory::class);
        $mock = \Mockery::mock($factory)->makePartial();
        $mock->shouldReceive('download')->once()->andThrow(new \RuntimeException('FAKE INTERNAL DETAILS'));
        app()->instance(ResponseFactory::class, $mock);
        $this->post(route('invoices.sales-register.export'), array_replace($payload, [
            'jpk_action' => 'download', 'jpk_fingerprint' => $review['fingerprint'], 'jpk_confirm' => '1',
        ]))->assertStatus(422)->assertDontSee('FAKE INTERNAL DETAILS');
        $this->assertSame($before, glob(storage_path('app/private/sales-register-exports/jpk-*.xml')));
    }

    public function test_form_review_preserves_legacy_options_and_can_render_isolated_visual_fixture(): void
    {
        $invoice = $this->invoice();
        $payload = $this->payload([$invoice->id]);
        $payload['jpk_markers'] = [];
        $response = $this->post(route('invoices.sales-register.export'), $payload)->assertOk()
            ->assertSee('Eksport części sprzedażowej do programu księgowego.')
            ->assertSee('Kontrola eksportu. Liczba dokumentów: 1')->assertSee('JPK_V7M (3) – KSeF');
        $this->assertSame('0', $response->viewData('values')['include_ksef']);
        $this->assertArrayNotHasKey('xml', $response->viewData('jpkReview'));
        $directory = getenv('SALES_REGISTER_PREVIEW_DIR');
        if (is_string($directory) && is_dir($directory)) {
            file_put_contents($directory.'/jpk-review.html', $response->getContent());
        }
    }

    public function test_official_schemas_are_pinned_without_changes(): void
    {
        $hashes = ['schemat.xsd' => '1870324001ba1318f0535841b963571e970b45ef32ba5ca3d433f0dc5da412df',
            'KodyKrajow_v13-0E.xsd' => '447efc6e1a84de71d433666c60635856b8e01f090e5eedb3410b1afe545f8117',
            'KodyUrzedowSkarbowych_v8-0E.xsd' => 'af27f583ea1ceb44ed37e0ec0d9bfdf2bfda74aadd5841ccbf0fc241175c2e91',
            'StrukturyDanych_v12-0E.xsd' => '01c45f7515f46cfa9bec843feef1409c126af3bee4d4e25f0325ae6323a9d512'];
        foreach ($hashes as $file => $hash) {
            $this->assertSame($hash, hash_file('sha256', base_path('Modules/Invoices/Resources/Schemas/JPK_V7M3/1-0E/'.$file)));
        }
    }

    public function test_malformed_correction_tax_identity_is_a_controlled_document_error(): void
    {
        $invoice = $this->correction($this->state(), $this->state());
        $item = $invoice->items()->first();
        $before = $item->correction_before_snapshot;
        $before['vat_rate'] = ['INVALID'];
        $item->update(['correction_before_snapshot' => $before]);
        $this->blocked($this->payload([$invoice->id]), 'pozycja');
    }

    public function test_foreign_correction_uses_saved_negative_pln_difference_once(): void
    {
        $invoice = $this->correction($this->state('23.00', '246'), $this->state('23.00', '123'));
        $invoice->update(['currency' => 'EUR']);
        $this->conversion($invoice, '23.00', '-400.00', '-92.00', '-492.00');
        $xp = $this->xpath($this->export($this->payload([$invoice->id])));
        $this->assertSame('-400.00', $xp->evaluate('string(//j:K_19)'));
        $this->assertSame('-92.00', $xp->evaluate('string(//j:PodatekNalezny)'));
    }

    public function test_equal_dates_omit_sale_date_and_correction_never_borrows_source_ksef_number(): void
    {
        $source = $this->invoice();
        $this->submission($source);
        $invoice = $this->correction($this->state(), $this->state('23.00', '246'));
        $invoice->update(['corrected_invoice_id' => $source->id, 'sale_date' => '2026-09-01']);
        $xp = $this->xpath($this->export($this->payload([$invoice->id])));
        $this->assertSame(0, $xp->query('//j:NrKSeF | //j:DataSprzedazy')->length);
        $this->assertSame('1', $xp->evaluate('string(//j:BFK)'));
    }

    private function blocked(array $payload, ?string $message = null): void
    {
        $review = $this->post(route('invoices.sales-register.export'), $payload)->assertOk()->viewData('jpkReview');
        $this->assertNotEmpty($review['errors']);
        if ($message !== null) {
            $this->assertStringContainsString($message, implode(' ', $review['errors']));
        }
        $this->post(route('invoices.sales-register.export'), array_replace($payload, [
            'jpk_action' => 'download', 'jpk_fingerprint' => $review['fingerprint'], 'jpk_confirm' => '1',
        ]))->assertStatus(422)->assertHeaderMissing('Content-Disposition');
    }

    private function conversion(Invoice $invoice, string $rate, string $net, string $vat, string $gross): void
    {
        $invoice->update(['tax_metadata_snapshot' => $invoice->tax_metadata_snapshot + [
            'currency_conversion' => ['version' => 1, 'source' => 'NBP', 'source_currency' => 'EUR', 'target_currency' => 'PLN',
                'table_type' => 'A', 'table_number' => 'FAKE/A/2026', 'effective_date' => '2026-08-31', 'reference_date' => '2026-09-01',
                'rate' => '4.0000', 'rate_rule' => 'vat_art_31a_standard_v1', 'rounding_mode' => 'half_up', 'result_scale' => 2],
            'converted_tax_summary' => ['currency' => 'PLN', 'groups' => [['vat_rate' => $rate, 'vat_code' => null, 'net' => $net, 'vat' => $vat, 'gross' => $gross]],
                'total_net' => $net, 'total_vat' => $vat, 'total_gross' => $gross],
        ]]);
    }

    private function offline(Invoice $invoice, string $procedure): void
    {
        DB::table('ksef_offline_issuances')->insert([
            'invoice_id' => $invoice->id, 'environment' => 'production', 'procedure' => $procedure, 'issue_date' => '2026-09-01',
            'issued_at' => '2026-09-01 12:00:00', 'seller_nip' => '1234563218', 'context_identifier_type' => 'Nip',
            'context_identifier_value' => '1234563218', 'schema_id' => 'FA(3)', 'payload_xml' => 'FAKE_UNREAD',
            'invoice_hash' => base64_encode(hash('sha256', 'FAKE', true)), 'invoice_size' => 4, 'certificate_serial_number' => 'FAKE',
            'certificate_fingerprint_sha256' => str_repeat('a', 64), 'certificate_valid_from' => '2026-01-01 00:00:00',
            'certificate_valid_until' => '2026-12-31 00:00:00', 'certificate_remote_status' => 'Active',
            'certificate_remote_valid_from' => '2026-01-01 00:00:00', 'certificate_remote_valid_until' => '2026-12-31 00:00:00',
            'certificate_remote_verified_at' => '2026-09-01 12:00:00', 'invoice_verification_url' => 'FAKE', 'certificate_verification_url' => 'FAKE',
        ]);
    }

    private function export(array $payload): string
    {
        $review = $this->post(route('invoices.sales-register.export'), $payload)->assertOk()->viewData('jpkReview');
        $this->assertSame([], $review['errors']);
        $response = $this->post(route('invoices.sales-register.export'), array_replace($payload, [
            'jpk_action' => 'download', 'jpk_fingerprint' => $review['fingerprint'], 'jpk_confirm' => '1',
        ]))->assertOk()->assertDownload('jpk_v7m_3_sprzedaz_2026_09.xml')
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $path = $response->baseResponse->getFile()->getPathname();
        $xml = file_get_contents($path);
        $this->assertSame([], app(JpkV7m3SchemaValidator::class)->errors($xml));
        ob_start();
        try {
            $response->baseResponse->sendContent();
        } finally {
            ob_end_clean();
        }
        $this->assertFileDoesNotExist($path);

        return $xml;
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml, LIBXML_NONET));
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('j', JpkV7m3SchemaValidator::NS);
        $xp->registerNamespace('e', JpkV7m3SchemaValidator::TYPES_NS);

        return $xp;
    }

    private function payload(array $ids): array
    {
        return ['mode' => 'ids', 'document_ids' => json_encode($ids), 'format' => 'jpk_v7m3', 'include_ksef' => 0,
            'jpk_action' => 'review', 'jpk_year' => '2026', 'jpk_month' => '9', 'jpk_type' => 'organization', 'jpk_nip' => '1234563218',
            'jpk_name' => 'Podatnik fikcyjny', 'jpk_email' => 'test@example.test', 'jpk_office' => '0202', 'jpk_purpose' => '1',
            'jpk_markers' => array_fill_keys($ids, 'BFK')];
    }

    private function period(): array
    {
        return array_replace($this->payload([]), ['mode' => 'period', 'month' => 9, 'year' => 2026, 'tax_id_presence' => 'all',
            'series_ids' => InvoiceSeries::whereIn('system_key', ['invoice', 'correction'])->pluck('id')->all()]);
    }

    private function state(string $rate = '23.00', string $gross = '123'): array
    {
        return ['name' => 'Produkt fikcyjny', 'quantity' => '1.0000', 'line_type' => 'product', 'vat_rate' => $rate, 'vat_code' => null, 'gtu_codes' => []]
            + app(InvoiceTotalsCalculator::class)->calculateLine($gross, $gross, $rate);
    }

    private function invoice(array $attributes = [], ?array $states = null, array $treatments = []): Invoice
    {
        $states ??= [$this->state()];
        $type = $attributes['document_type'] ?? 'invoice';
        $invoice = Invoice::create(array_replace([
            'invoice_series_id' => InvoiceSeries::where('system_key', $type)->value('id'), 'document_type' => $type,
            'status' => 'issued', 'number' => 'JPK '.++$this->sequence, 'currency' => 'PLN', 'issue_date' => '2026-09-01', 'sale_date' => '2026-08-31',
            'buyer_name_snapshot' => 'Klient fikcyjny', 'buyer_tax_id_snapshot' => null, 'buyer_snapshot' => ['name' => 'Klient fikcyjny', 'tax_id' => null],
            'seller_tax_id_snapshot' => '1234563218', 'seller_snapshot' => ['name' => 'Podatnik fikcyjny', 'tax_id' => '1234563218'],
        ], app(InvoiceTotalsCalculator::class)->calculateEditedDocument($states, '0.00'), $attributes));
        $entries = [];
        foreach ($states as $index => $state) {
            $item = $invoice->items()->create(array_replace($state, ['position' => $index + 1]));
            $entries[] = ['invoice_item_id' => $item->id, 'position' => $item->position] + $this->meaning($state, $treatments[$index] ?? 'standard');
        }
        $invoice->update(['tax_metadata_snapshot' => ['ksef_tax' => ['version' => 1, 'profile' => 'ordinary', 'line_treatments' => $entries]]]);

        return $invoice;
    }

    private function meaning(array $state, string $treatment): array
    {
        return app(KsefFa3TaxTreatmentResolver::class)->resolveSnapshot($state['vat_rate'], $state['vat_code'], match ($treatment) {
            'export' => KsefZeroVatClassification::Export, 'domestic_zero' => KsefZeroVatClassification::Domestic, default => KsefZeroVatClassification::Wdt,
        });
    }

    private function correction(array $before, array $after, string $beforeTreatment = 'standard', string $afterTreatment = 'standard'): Invoice
    {
        $totals = app(CorrectionTotalsCalculator::class)->calculate([['correction_before_snapshot' => $before, 'correction_after_snapshot' => $after]]);
        $invoice = $this->invoice(['document_type' => 'correction', 'total_net' => $totals['difference']['net'],
            'total_vat' => $totals['difference']['vat'], 'total_gross' => $totals['difference']['gross'],
            'tax_summary_snapshot' => $totals['difference']['tax_summary_snapshot'], 'correction_totals_snapshot' => $totals], [$after]);
        $item = $invoice->items()->first();
        $item->update(['correction_before_snapshot' => $before, 'correction_after_snapshot' => $after]);
        $invoice->update(['tax_metadata_snapshot' => ['ksef_correction' => ['version' => 1, 'profile' => 'correction', 'line_treatments' => [[
            'invoice_item_id' => $item->id, 'source_invoice_item_id' => null, 'position' => $item->position,
            'before' => $this->meaning($before, $beforeTreatment), 'after' => $this->meaning($after, $afterTreatment),
        ]]]]]);

        return $invoice;
    }

    private function submission(Invoice $invoice, array $attributes = []): void
    {
        DB::table('ksef_invoice_submissions')->insert(array_replace([
            'invoice_id' => $invoice->id, 'environment' => 'production', 'attempt_number' => DB::table('ksef_invoice_submissions')->count() + 1,
            'status' => 'accepted', 'schema_id' => 'FA(3)', 'generated_at' => '2026-09-01 12:00:00', 'seller_nip' => '1234563218', 'context_nip' => '1234563218',
            'payload_xml' => 'FAKE', 'invoice_hash' => base64_encode(hash('sha256', 'FAKE', true)), 'invoice_size' => 4, 'invoicing_mode' => 'Online',
            'ksef_number' => '1234563218-20260901-000000000001-CA',
        ], $attributes));
    }
}
