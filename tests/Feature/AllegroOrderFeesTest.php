<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AllegroOrderFixture as Fixture;
use Tests\TestCase;

class AllegroOrderFeesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_fees_use_the_order_and_marketplace_filters_and_do_not_change_financial_records(): void
    {
        [$order, $account, $link] = $this->order();
        $item = $order->items()->create(['product_name' => 'Produkt', 'quantity' => 1, 'unit_price_gross' => '208.00', 'total_price_gross' => '208.00', 'currency' => 'PLN']);
        $payment = $link->payments()->create(['external_payment_id' => Fixture::PAYMENT_ID, 'type' => 'ONLINE', 'provider' => 'AF', 'amount' => '208.00', 'currency' => 'PLN']);
        DB::table('invoices')->insert([
            'order_id' => $order->id,
            'invoice_series_id' => DB::table('invoice_series')->where('system_key', 'invoice')->value('id'),
            'document_type' => 'invoice', 'status' => 'draft', 'total_gross' => '208.00',
            'buyer_snapshot' => json_encode(['name' => 'Historyczny nabywca']),
        ]);
        $before = [$order->fresh()->getAttributes(), $item->fresh()->getAttributes(), $payment->fresh()->getAttributes(), DB::table('invoices')->get()->toJson()];
        $this->fakeEntries([$this->entry(), $this->entry(['asset' => 'CREDIT', 'value' => ['amount' => '12.30']])]);

        $response = $this->postJson(route('orders.allegro-fees.refresh', $order))->assertOk();
        $this->assertStringContainsString('28.15 PLN', $response->json('html'));
        $this->assertStringContainsString('34.62 PLN', $response->json('html'));
        $this->assertStringContainsString('-10.00 PLN', $response->json('html'));
        $this->assertStringContainsString('-12.30 PLN', $response->json('html'));
        $this->assertStringNotContainsString('Aktualizacja opłat:', $response->json('html'));
        $this->assertNotNull($link->fresh()->fees_refreshed_at);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $item->fresh()->getAttributes(), $payment->fresh()->getAttributes(), DB::table('invoices')->get()->toJson()]);
        $this->assertSame(2, $link->fresh()->fees_snapshot['entry_count']);
        $this->assertSame($account->granted_scopes, $account->fresh()->granted_scopes);
        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET' && $query === [
                'order_id' => Fixture::ORDER_ID, 'marketplaceId' => 'allegro-pl', 'limit' => '100', 'offset' => '0',
            ] && $request->hasHeader('Accept-Language', 'pl-PL');
        });
        Http::assertSentCount(1);
        $log = DB::table('integration_api_logs')->sole();
        $this->assertSame('get_order_fees', $log->operation);
        $this->assertSame(['count' => 2], json_decode($log->response_payload, true));
    }

    public function test_pagination_deduplicates_entries_and_rounds_net_for_each_entry_before_grouping(): void
    {
        [$order, , $link] = $this->order();
        $entries = array_map(fn () => $this->entry(['value' => ['amount' => '-0.01']]), range(1, 101));
        $offsets = [];
        Http::fake(['api.allegro.pl/billing/billing-entries*' => function ($request) use ($entries, &$offsets) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $offsets[] = $query['offset'];

            return Http::response(['billingEntries' => $query['offset'] === '0' ? array_slice($entries, 0, 100) : [$entries[99], $entries[100]]]);
        }]);
        $this->postJson(route('orders.allegro-fees.refresh', $order))->assertOk();
        $this->assertSame(['0', '100'], $offsets);
        $snapshot = $link->fresh()->fees_snapshot;
        $this->assertSame(101, $snapshot['entry_count']);
        $this->assertCount(1, $snapshot['rows']);
        $this->assertSame('1.01', $snapshot['rows'][0]['gross']);
        $this->assertSame('1.01', $snapshot['rows'][0]['net']);
    }

    #[DataProvider('taxCases')]
    public function test_tax_annotations_and_unknown_tax_never_assume_twenty_three_percent(array $tax, ?string $net): void
    {
        [$order, , $link] = $this->order();
        $entry = $this->entry();
        $entry['tax'] = $tax;
        $this->fakeEntries([$entry]);
        $this->postJson(route('orders.allegro-fees.refresh', $order))->assertOk();
        $this->assertSame($net, $link->fresh()->fees_snapshot['rows'][0]['net']);
    }

    public static function taxCases(): array
    {
        return [
            'zero' => [['percentage' => '0'], '34.62'],
            'not applicable' => [['annotation' => 'NOT_APPLICABLE'], '34.62'],
            'out of scope' => [['annotation' => 'OUT_OF_SCOPE'], '34.62'],
            'legacy exempt' => [['annotation' => 'EXEMPT'], '34.62'],
            'unknown' => [[], null],
        ];
    }

    public function test_currencies_are_kept_separate_and_names_are_escaped(): void
    {
        [$order, , $link] = $this->order();
        $this->fakeEntries([$this->entry(), $this->entry([
            'type' => ['name' => '<img src=x onerror=alert(1)>'],
            'value' => ['amount' => '-34.62', 'currency' => 'CZK'],
        ])]);
        $response = $this->postJson(route('orders.allegro-fees.refresh', $order))->assertOk();
        $this->assertCount(2, $link->fresh()->fees_snapshot['rows']);
        $this->assertStringContainsString('34.62 PLN', $response->json('html'));
        $this->assertStringContainsString('34.62 CZK', $response->json('html'));
        $this->assertStringContainsString('&lt;img', $response->json('html'));
        $this->assertStringNotContainsString('<img src=x', $response->json('html'));
    }

    #[DataProvider('invalidEntries')]
    public function test_invalid_or_unrelated_entries_do_not_replace_the_saved_snapshot(array $override): void
    {
        [$order, , $link] = $this->order();
        $link->update(['fees_snapshot' => ['rows' => [], 'entry_count' => 7], 'fees_refreshed_at' => now()->subDay()]);
        $before = $link->getAttributes();
        $this->fakeEntries([$this->entry($override)]);
        $this->postJson(route('orders.allegro-fees.refresh', $order))->assertUnprocessable();
        $this->assertSame($before, $link->fresh()->getAttributes());
    }

    public static function invalidEntries(): array
    {
        return [
            'wrong order' => [['order' => ['id' => '11111111-1111-4111-8111-111111111111']]],
            'missing order' => [['order' => null]],
            'wrong market' => [['marketplaceId' => 'allegro-cz']],
            'invalid id' => [['id' => 'bad-id']],
            'invalid asset' => [['asset' => 'UNKNOWN']],
            'positive debit' => [['value' => ['amount' => '34.62']]],
            'negative credit' => [['asset' => 'CREDIT']],
            'float amount' => [['value' => ['amount' => 34.62]]],
            'invalid precision' => [['value' => ['amount' => '-34.621']]],
            'invalid tax' => [['tax' => ['percentage' => '101']]],
            'contradictory tax' => [['tax' => ['annotation' => 'NOT_APPLICABLE']]],
        ];
    }

    public function test_later_page_failure_keeps_previous_fees_and_empty_response_is_not_a_zero_fee(): void
    {
        [$order, , $link] = $this->order();
        $snapshot = ['rows' => [], 'entry_count' => 3];
        $link->update(['fees_snapshot' => $snapshot]);
        Http::fakeSequence('api.allegro.pl/billing/billing-entries*')
            ->push(['billingEntries' => array_map(fn () => $this->entry(), range(1, 100))])
            ->push([], 429);
        $this->postJson(route('orders.allegro-fees.refresh', $order))->assertUnprocessable();
        $this->assertSame($snapshot, $link->fresh()->fees_snapshot);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeEntries([]);
        $response = $this->postJson(route('orders.allegro-fees.refresh', $order))->assertOk();
        $this->assertStringContainsString('Brak opłat przypisanych', $response->json('html'));
        $this->assertSame(0, $link->fresh()->fees_snapshot['entry_count']);
    }

    public function test_billing_scope_is_optional_for_orders_but_required_before_fetching_fees(): void
    {
        [$order, $account] = $this->order();
        $account->update(['granted_scopes' => AllegroAccount::REQUIRED_SCOPES]);
        $this->assertTrue($account->fresh()->isOperational());
        $this->postJson(route('orders.allegro-fees.refresh', $order))->assertUnprocessable()->assertJsonPath('message', 'Brak uprawnienia do rozliczeń Allegro. Włącz odczyt rozliczeń w aplikacji Allegro i ponownie połącz integrację. Obsługa zamówień działa bez zmian.');
        $this->get(route('integrations.allegro.edit'))->assertOk()->assertSee('Połącz ponownie')->assertSee('Obsługa zamówień pozostaje aktywna.');
        Http::assertNothingSent();
    }

    public function test_wrong_account_and_account_change_during_fetch_cannot_overwrite_fees(): void
    {
        [$order, $account, $link] = $this->order();
        $account->update(['environment' => 'sandbox']);
        $this->postJson(route('orders.allegro-fees.refresh', $order))->assertUnprocessable();
        Http::assertNothingSent();
        $account->update(['environment' => 'production']);
        Http::fake(['api.allegro.pl/billing/billing-entries*' => function () use ($account) {
            $account->update(['allegro_user_id' => 'other-seller']);

            return Http::response(['billingEntries' => [$this->entry()]]);
        }]);
        $this->postJson(route('orders.allegro-fees.refresh', $order))->assertUnprocessable();
        $this->assertNull($link->fresh()->fees_snapshot);
    }

    public function test_deleted_accounts_keep_the_last_fees_readable_but_cannot_fetch_again(): void
    {
        [$order, $account] = $this->order();
        $this->fakeEntries([$this->entry()]);
        $this->postJson(route('orders.allegro-fees.refresh', $order))->assertOk();
        $account->delete();
        $this->get(route('orders.show', $order))->assertOk()
            ->assertSee('28.15 PLN')->assertSee('34.62 PLN')
            ->assertDontSee('Aktualizacja opłat:');
        $this->postJson(route('orders.allegro-fees.refresh', $order))->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_repeated_full_pages_stop_without_saving_partial_results(): void
    {
        [$order, , $link] = $this->order();
        $entries = array_map(fn () => $this->entry(), range(1, 100));
        $this->fakeEntries($entries);
        $this->postJson(route('orders.allegro-fees.refresh', $order))->assertUnprocessable();
        $this->assertNull($link->fresh()->fees_snapshot);
        Http::assertSentCount(2);
    }

    private function order(): array
    {
        $account = AllegroAccount::query()->create([
            'account_key' => 'default', 'name' => 'multi-click', 'environment' => 'production',
            'client_id' => 'client', 'client_secret' => 'secret', 'is_active' => true,
            'access_token' => 'token', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addHour(),
            'granted_scopes' => [...AllegroAccount::REQUIRED_SCOPES, AllegroAccount::BILLING_SCOPE],
            'allegro_user_id' => '12345678', 'allegro_login' => 'multi-click', 'marketplace_id' => 'allegro-pl', 'last_test_status' => 'success',
        ]);
        $order = Order::query()->create(['source' => 'allegro', 'status' => 'new', 'currency' => 'PLN', 'total_gross' => '208.00', 'paid_amount' => '208.00', 'payment_status' => 'paid']);
        $link = AllegroOrderLink::query()->create([
            'order_id' => $order->id, 'allegro_account_id' => $account->id, 'environment' => 'production',
            'seller_user_id' => $account->allegro_user_id, 'account_name' => $account->name,
            'external_order_id' => Fixture::ORDER_ID, 'marketplace_id' => 'allegro-pl',
            'external_status' => 'READY_FOR_PROCESSING', 'source_hash' => str_repeat('0', 64), 'imported_at' => now(),
        ]);

        return [$order->refresh(), $account, $link->refresh()];
    }

    private function entry(array $override = []): array
    {
        return array_replace_recursive([
            'id' => (string) Str::uuid(), 'order' => ['id' => Fixture::ORDER_ID], 'marketplaceId' => 'allegro-pl',
            'type' => ['id' => 'SUC', 'name' => 'Prowizja od sprzedaży'], 'asset' => 'DEBIT',
            'value' => ['amount' => '-34.62', 'currency' => 'PLN'], 'tax' => ['percentage' => '23'],
            'balance' => ['amount' => '-999.99', 'currency' => 'PLN'],
        ], $override);
    }

    private function fakeEntries(array $entries): void
    {
        Http::fake(['api.allegro.pl/billing/billing-entries*' => Http::response(['billingEntries' => $entries])]);
    }
}
