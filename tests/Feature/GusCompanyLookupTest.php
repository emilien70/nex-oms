<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Models\Invoice;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class GusCompanyLookupTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    private const API_KEY = 'TEST_GUS_API_KEY_DO_NOT_EXPOSE';

    private const NIP = '9876543210';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);

        config([
            'services.gus.key' => self::API_KEY,
            'services.gus.url' => 'https://gus.example.test/wsBIR/UslugaBIRzewnPubl.svc',
            'services.gus.timeout' => 10,
        ]);
        Http::preventStrayRequests();
    }

    public function test_it_rejects_nip_with_invalid_checksum_without_calling_gus(): void
    {
        $response = $this->postJson(route('gus.company-by-nip'), [
            'nip' => '123-456-78-90',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.nip.0', 'Wpisz prawidłowy polski NIP.');

        Http::assertNothingSent();
    }

    public function test_it_returns_structured_company_data_and_uses_sid_header(): void
    {
        $this->fakeLookup($this->searchResult([
            [
                'Regon' => '123456785',
                'Nip' => self::NIP,
                'Nazwa' => 'NEX Testowa Sp. z o.o.',
                'Wojewodztwo' => 'MAŁOPOLSKIE',
                'Miejscowosc' => 'Psary',
                'KodPocztowy' => '32-545',
                'Ulica' => 'Kamienna',
                'NrNieruchomosci' => '6',
                'NrLokalu' => '2',
                'Typ' => 'P',
                'SilosID' => '6',
            ],
        ]));

        $response = $this->postJson(route('gus.company-by-nip'), ['nip' => self::NIP]);

        $response->assertOk()
            ->assertJsonCount(1, 'companies')
            ->assertJsonPath('companies.0.name', 'NEX Testowa Sp. z o.o.')
            ->assertJsonPath('companies.0.nip', self::NIP)
            ->assertJsonPath('companies.0.regon', '123456785')
            ->assertJsonPath('companies.0.street', 'Kamienna')
            ->assertJsonPath('companies.0.buildingNumber', '6')
            ->assertJsonPath('companies.0.apartmentNumber', '2')
            ->assertJsonPath('companies.0.postalCode', '32-545')
            ->assertJsonPath('companies.0.city', 'Psary')
            ->assertJsonPath('companies.0.province', 'MAŁOPOLSKIE')
            ->assertJsonPath('companies.0.countryCode', 'PL')
            ->assertJsonMissing(['apiKey' => self::API_KEY]);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '<dat:Nip>'.self::NIP.'</dat:Nip>')
            && str_contains($request->body(), '<a:Action s:mustUnderstand="1">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</a:Action>')
            && str_contains($request->body(), '<a:To s:mustUnderstand="1">https://gus.example.test/wsBIR/UslugaBIRzewnPubl.svc</a:To>')
            && $request->hasHeader('sid', 'TEST_SESSION_ID_1234'));
    }

    public function test_it_reads_the_soap_envelope_from_an_official_mtom_response(): void
    {
        $boundary = 'uuid:test-gus-boundary';

        Http::fakeSequence()
            ->push(
                $this->multipartSoap(
                    $this->soap('<ZalogujResponse><ZalogujResult>TEST_SESSION_ID_1234</ZalogujResult></ZalogujResponse>'),
                    $boundary,
                ),
                200,
                ['Content-Type' => 'multipart/related; type="application/xop+xml"; boundary="'.$boundary.'"; start-info="application/soap+xml"'],
            )
            ->push($this->soap('<DaneSzukajPodmiotyResponse><DaneSzukajPodmiotyResult><![CDATA['.$this->searchResult([
                [
                    'Nip' => self::NIP,
                    'Nazwa' => 'Firma z odpowiedzi MTOM',
                ],
            ]).']]></DaneSzukajPodmiotyResult></DaneSzukajPodmiotyResponse>'))
            ->push($this->soap('<WylogujResponse><WylogujResult>true</WylogujResult></WylogujResponse>'));

        $this->postJson(route('gus.company-by-nip'), ['nip' => self::NIP])
            ->assertOk()
            ->assertJsonPath('companies.0.name', 'Firma z odpowiedzi MTOM');
    }

    public function test_it_returns_all_distinct_entries_for_the_same_nip(): void
    {
        $this->fakeLookup($this->searchResult([
            [
                'Regon' => '987654321',
                'Nip' => self::NIP,
                'Nazwa' => 'Przedsiębiorca Testowy',
                'Miejscowosc' => 'Kraków',
                'KodPocztowy' => '30-001',
                'Ulica' => 'Długa',
                'NrNieruchomosci' => '1',
                'Typ' => 'F',
                'SilosID' => '1',
            ],
            [
                'Regon' => '987654321',
                'Nip' => self::NIP,
                'Nazwa' => 'Gospodarstwo Testowe',
                'Miejscowosc' => 'Zielona Wieś',
                'KodPocztowy' => '32-100',
                'NrNieruchomosci' => '12A',
                'Typ' => 'F',
                'SilosID' => '2',
            ],
        ]));

        $this->postJson(route('gus.company-by-nip'), ['nip' => self::NIP])
            ->assertOk()
            ->assertJsonCount(2, 'companies')
            ->assertJsonPath('companies.0.siloId', '1')
            ->assertJsonPath('companies.1.siloId', '2')
            ->assertJsonPath('companies.1.street', '')
            ->assertJsonPath('companies.1.buildingNumber', '12A');
    }

    public function test_official_not_found_error_is_returned_as_not_found(): void
    {
        $this->fakeLookup($this->searchResult([
            [
                'ErrorCode' => '4',
                'ErrorMessagePl' => 'Nie znaleziono podmiotu dla podanych kryteriów wyszukiwania.',
                'Nip' => self::NIP,
            ],
        ]));

        $this->postJson(route('gus.company-by-nip'), ['nip' => self::NIP])
            ->assertNotFound()
            ->assertJsonPath('message', 'Nie znaleziono firmy dla podanego NIP.');
    }

    public function test_empty_search_uses_get_value_diagnostic_without_exposing_the_key(): void
    {
        Http::fakeSequence()
            ->push($this->soap('<ZalogujResponse><ZalogujResult>TEST_SESSION_ID_1234</ZalogujResult></ZalogujResponse>'))
            ->push($this->soap('<DaneSzukajPodmiotyResponse><DaneSzukajPodmiotyResult /></DaneSzukajPodmiotyResponse>'))
            ->push($this->soap('<GetValueResponse><GetValueResult>4</GetValueResult></GetValueResponse>'))
            ->push($this->soap('<WylogujResponse><WylogujResult>true</WylogujResult></WylogujResponse>'));

        $response = $this->postJson(route('gus.company-by-nip'), ['nip' => self::NIP]);

        $response->assertNotFound();
        $this->assertStringNotContainsString(self::API_KEY, $response->getContent());
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '<bir:pNazwaParametru>KomunikatKod</bir:pNazwaParametru>'));
    }

    public function test_authentication_failure_returns_only_safe_diagnostics(): void
    {
        Http::fakeSequence()
            ->push($this->soap('<ZalogujResponse><ZalogujResult /></ZalogujResponse>'));

        $response = $this->postJson(route('gus.company-by-nip'), ['nip' => self::NIP]);

        $response->assertStatus(502)
            ->assertJsonPath('code', 'gus_authentication_failed');
        $this->assertStringNotContainsString(self::API_KEY, $response->getContent());
    }

    public function test_order_form_uses_post_csrf_and_safe_multiple_result_controls(): void
    {
        $order = $this->createOrder();

        $response = $this->get(route('orders.show', $order));

        $response->assertOk()
            ->assertSee('data-gus-results', false)
            ->assertSee('data-gus-results-select', false)
            ->assertSee("method: 'POST'", false)
            ->assertSee("'X-CSRF-TOKEN': csrfToken", false)
            ->assertSee('body: JSON.stringify({ nip })', false)
            ->assertSee("setFormValue(billingForm, 'billing_name', '');", false)
            ->assertSee('name="billing_province"', false)
            ->assertDontSee(self::API_KEY);
    }

    public function test_invoice_uses_saved_gus_form_data_as_an_immutable_buyer_snapshot(): void
    {
        $order = $this->createDocumentOrder([
            'billing_name' => null,
            'billing_company_name' => 'Firma pobrana z GUS',
            'billing_tax_id' => self::NIP,
            'billing_street' => null,
            'billing_building_number' => '12A',
            'billing_apartment_number' => '3',
            'billing_postal_code' => '32-100',
            'billing_city' => 'Zielona Wieś',
            'billing_province' => 'MAŁOPOLSKIE',
            'billing_country_code' => 'PL',
        ]);
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();

        $invoiceId = $this->postJson(route('orders.invoice.store', $order), [
            'invoice_series_id' => $series->getKey(),
        ])->assertCreated()->json('document.id');

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->assertSame('Firma pobrana z GUS', $invoice->buyer_snapshot['company_name']);
        $this->assertSame(self::NIP, $invoice->buyer_snapshot['tax_id']);
        $this->assertNull($invoice->buyer_snapshot['street']);
        $this->assertSame('12A', $invoice->buyer_snapshot['building_number']);
        $this->assertSame('3', $invoice->buyer_snapshot['apartment_number']);
        $this->assertSame('MAŁOPOLSKIE', $invoice->buyer_snapshot['province']);
        $this->assertSame('PL', $invoice->buyer_snapshot['country_code']);

        $order->update(['billing_company_name' => 'Późniejsza nazwa w zamówieniu']);

        $this->assertSame('Firma pobrana z GUS', $invoice->fresh()->buyer_snapshot['company_name']);
    }

    private function fakeLookup(string $result): void
    {
        Http::fakeSequence()
            ->push($this->soap('<ZalogujResponse><ZalogujResult>TEST_SESSION_ID_1234</ZalogujResult></ZalogujResponse>'))
            ->push($this->soap('<DaneSzukajPodmiotyResponse><DaneSzukajPodmiotyResult><![CDATA['.$result.']]></DaneSzukajPodmiotyResult></DaneSzukajPodmiotyResponse>'))
            ->push($this->soap('<WylogujResponse><WylogujResult>true</WylogujResult></WylogujResponse>'));
    }

    /** @param  list<array<string, string>>  $rows */
    private function searchResult(array $rows): string
    {
        $xml = '<root>';

        foreach ($rows as $row) {
            $xml .= '<dane>';

            foreach ($row as $name => $value) {
                $xml .= '<'.$name.'>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</'.$name.'>';
            }

            $xml .= '</dane>';
        }

        return $xml.'</root>';
    }

    private function soap(string $body): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body>'
            .$body
            .'</s:Body></s:Envelope>';
    }

    private function multipartSoap(string $soap, string $boundary): string
    {
        return '--'.$boundary."\r\n"
            .'Content-ID: <http://tempuri.org/0>'."\r\n"
            .'Content-Transfer-Encoding: 8bit'."\r\n"
            .'Content-Type: application/xop+xml; charset=utf-8; type="application/soap+xml"'."\r\n\r\n"
            .$soap."\r\n"
            .'--'.$boundary.'--'."\r\n";
    }

    private function createOrder(): Order
    {
        return Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '0.00',
            'payment_status' => 'unpaid',
            'shipping_name' => 'Anna Nowak',
            'shipping_street' => 'Testowa',
            'shipping_building_number' => '1',
            'shipping_postal_code' => '00-001',
            'shipping_city' => 'Warszawa',
            'billing_name' => 'Jan Kowalski',
            'billing_street' => 'Fakturowa',
            'billing_building_number' => '2',
            'billing_postal_code' => '00-002',
            'billing_city' => 'Warszawa',
            'billing_province' => 'MAZOWIECKIE',
            'billing_country_code' => 'PL',
        ]);
    }
}
