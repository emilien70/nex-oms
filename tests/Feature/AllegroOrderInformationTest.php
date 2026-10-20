<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroOrderImportService;
use Modules\Integrations\Allegro\Services\AllegroOrderPreviewService;
use Tests\Support\AllegroOrderFixture as Fixture;
use Tests\TestCase;

class AllegroOrderInformationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_import_keeps_details_and_page_places_sections_before_payments_without_requiring_billing_access(): void
    {
        [$order, $account] = $this->importOrder();
        $link = $order->allegroOrderLink;
        $this->assertSame('2026-10-19T22:00:00+00:00', $link->details_snapshot['dispatch_from']);
        $this->assertSame('100.03', $link->details_snapshot['total']['amount']);
        $this->assertNotNull($link->details_refreshed_at);

        $page = $this->get(route('orders.show', $order))->assertOk()
            ->assertSeeInOrder(['>Szczegóły zamówienia</button>', '>Prowizje i opłaty ', '>Płatności</button>'], false)
            ->assertSee('20.10.2026 00:00:00')
            ->assertSee('21.10.2026 23:59:59')
            ->assertSee('ONLINE Allegro Finance')
            ->assertSee('Allegro SMART:')
            ->assertSee('Deklarowana ilość paczek: 1')
            ->assertSee('Karta transakcji')
            ->assertSee('Pobrano:')
            ->assertDontSee('Aktualizacja szczegółów:')
            ->assertDontSee('Aktualizacja opłat:')
            ->assertSee('Nie pobrano prowizji i opłat.')
            ->assertDontSee('billing/billing-entries');
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//button[@data-allegro-information-refresh]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="orderAllegroFeesCollapse"][@data-refresh-on-open="1"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="orderAllegroDetailsCollapse"][@data-refresh-on-open]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="orderAllegroDetailsCollapse"][@data-information-loaded="1"]')->length);
        $this->assertSame(AllegroAccount::REQUIRED_SCOPES, $account->fresh()->granted_scopes);
        $this->assertStringContainsString(AllegroAccount::BILLING_SCOPE, config('services.allegro.scopes'));
        $this->assertTrue($account->fresh()->isOperational());
        Http::assertSentCount(2);
    }

    public function test_refresh_backfills_details_without_changing_order_items_payments_or_document_snapshots(): void
    {
        [$order] = $this->importOrder();
        $order->update(['notes' => 'Numery seryjne', 'shipping_street' => 'Ręcznie poprawiony adres']);
        $order->allegroOrderLink->update(['details_snapshot' => null, 'details_refreshed_at' => null]);
        $invoiceId = DB::table('invoices')->insertGetId([
            'order_id' => $order->id,
            'invoice_series_id' => DB::table('invoice_series')->where('system_key', 'invoice')->value('id'),
            'document_type' => 'invoice',
            'status' => 'draft',
            'buyer_snapshot' => json_encode(['name' => 'Nabywca historyczny']),
            'total_gross' => '100.03',
        ]);
        $before = $order->fresh()->getAttributes();
        $items = DB::table('order_items')->get()->toJson();
        $payments = DB::table('allegro_order_payments')->get()->toJson();
        $invoice = (array) DB::table('invoices')->find($invoiceId);
        $events = DB::table('order_events')->get()->toJson();
        $payload = $this->payload();
        $payload['summary']['totalToPay']['amount'] = '999.99';
        $payload['delivery']['address']['street'] = 'Aktualny adres Allegro';
        $this->fakePayload($payload);

        $response = $this->postJson(route('orders.allegro-information.refresh', $order))->assertOk();
        $this->assertStringContainsString('999.99 PLN', $response->json('html'));
        $this->assertStringContainsString('Aktualny adres Allegro', $response->json('html'));
        $this->assertStringContainsString('Pobrano:', $response->json('html'));
        $this->assertStringNotContainsString('Aktualizacja szczegółów:', $response->json('html'));
        $this->assertNotNull($order->allegroOrderLink->fresh()->details_refreshed_at);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame($items, DB::table('order_items')->get()->toJson());
        $this->assertSame($payments, DB::table('allegro_order_payments')->get()->toJson());
        $this->assertSame($invoice, (array) DB::table('invoices')->find($invoiceId));
        $this->assertSame($events, DB::table('order_events')->get()->toJson());
        $this->assertSame('999.99', $order->allegroOrderLink->fresh()->details_snapshot['total']['amount']);
    }

    public function test_wrong_account_and_environment_are_rejected_before_http_and_keep_stored_details(): void
    {
        [$order, $account] = $this->importOrder();
        $snapshot = $order->allegroOrderLink->details_snapshot;
        $account->update(['allegro_user_id' => 'other-seller']);
        $this->postJson(route('orders.allegro-information.refresh', $order))->assertUnprocessable();
        $account->update(['allegro_user_id' => '12345678', 'environment' => 'sandbox']);
        $this->postJson(route('orders.allegro-information.refresh', $order))->assertUnprocessable();
        $this->assertSame($snapshot, $order->allegroOrderLink->fresh()->details_snapshot);
        Http::assertSentCount(2);
    }

    public function test_account_change_during_http_and_wrong_order_response_do_not_overwrite_snapshot(): void
    {
        [$order, $account] = $this->importOrder();
        $snapshot = $order->allegroOrderLink->details_snapshot;
        $payload = $this->payload();
        $payload['id'] = '11111111-1111-4111-8111-111111111111';
        $this->fakePayload($payload);
        $this->postJson(route('orders.allegro-information.refresh', $order))->assertUnprocessable();

        $this->fakePayload(function () use ($account) {
            $account->update(['allegro_user_id' => 'new-seller']);

            return Http::response($this->payload());
        });
        $this->postJson(route('orders.allegro-information.refresh', $order))->assertUnprocessable();
        $this->assertSame($snapshot, $order->allegroOrderLink->fresh()->details_snapshot);
    }

    public function test_http_errors_keep_saved_data_and_deleted_account_keeps_readable_details(): void
    {
        [$order, $account] = $this->importOrder();
        $snapshot = $order->allegroOrderLink->details_snapshot;
        $this->fakePayload([], 429);
        $this->postJson(route('orders.allegro-information.refresh', $order))->assertUnprocessable();
        $this->assertSame($snapshot, $order->allegroOrderLink->fresh()->details_snapshot);
        $account->delete();
        $this->get(route('orders.show', $order))->assertOk()->assertSee('ONLINE Allegro Finance');
        $this->postJson(route('orders.allegro-information.refresh', $order))->assertUnprocessable();
    }

    public function test_missing_dates_and_html_are_handled_without_fabricated_values_or_markup(): void
    {
        [$order] = $this->importOrder();
        $payload = $this->payload();
        $payload['delivery']['time'] = ['from' => 'bad-date', 'to' => null];
        $payload['delivery']['calculatedNumberOfPackages'] = -1;
        $payload['messageToSeller'] = '<img src=x onerror=alert(1)>';
        $this->fakePayload($payload);
        $response = $this->postJson(route('orders.allegro-information.refresh', $order))->assertOk();
        $this->assertStringContainsString('od: ...', $response->json('html'));
        $this->assertStringContainsString(e($payload['messageToSeller']), $response->json('html'));
        $this->assertStringNotContainsString('<img src=x', $response->json('html'));
        $this->assertStringContainsString('Deklarowana ilość paczek: ...', $response->json('html'));
        $this->assertNull($order->allegroOrderLink->fresh()->details_snapshot['delivery_from']);
    }

    public function test_manual_orders_and_orders_without_links_do_not_offer_or_fetch_allegro_data(): void
    {
        $order = Order::query()->create(['source' => 'manual', 'status' => 'new', 'currency' => 'PLN']);
        $this->get(route('orders.show', $order))->assertOk()
            ->assertDontSee('data-allegro-information-panel data-information-loaded=', false)
            ->assertDontSee('>Prowizje i opłaty ', false);
        $this->postJson(route('orders.allegro-information.refresh', $order))->assertUnprocessable();
        $order->update(['source' => 'allegro']);
        $this->postJson(route('orders.allegro-information.refresh', $order))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_incomplete_api_response_does_not_replace_previously_saved_details(): void
    {
        [$order] = $this->importOrder();
        $snapshot = $order->allegroOrderLink->details_snapshot;
        $this->fakePayload(['id' => Fixture::ORDER_ID]);
        $this->postJson(route('orders.allegro-information.refresh', $order))->assertUnprocessable();
        $this->assertSame($snapshot, $order->allegroOrderLink->fresh()->details_snapshot);
    }

    private function payload(): array
    {
        $payload = Fixture::payload();
        $payload['payment']['provider'] = 'AF';
        $payload['delivery']['time'] = [
            'dispatch' => ['from' => '2026-10-19T22:00:00Z', 'to' => '2026-10-20T21:59:59Z'],
            'from' => '2026-10-20T22:00:00Z', 'to' => '2026-10-21T21:59:59Z',
        ];

        return $payload;
    }

    private function importOrder(): array
    {
        $account = AllegroAccount::query()->create([
            'account_key' => 'default', 'name' => 'multi-click', 'environment' => 'production',
            'client_id' => 'client', 'client_secret' => 'secret', 'is_active' => true,
            'access_token' => 'token', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHour(), 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'allegro_user_id' => '12345678', 'allegro_login' => 'multi-click',
            'marketplace_id' => 'allegro-pl', 'last_test_status' => 'success',
        ]);
        $this->fakePayload($this->payload());
        $preview = app(AllegroOrderPreviewService::class)->preview($account, Fixture::ORDER_ID);
        $order = app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);

        return [$order, $account];
    }

    private function fakePayload(array|callable $payload, int $status = 200): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.allegro.pl/order/checkout-forms/*' => is_array($payload) ? Http::response($payload, $status) : $payload]);
    }
}
