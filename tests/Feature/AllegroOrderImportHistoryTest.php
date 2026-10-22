<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Jobs\SyncAllegroOrderJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderImportEvent;
use Tests\TestCase;

class AllegroOrderImportHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_one_row_uses_the_latest_source_event_without_deleting_the_journal(): void
    {
        $account = $this->account();
        $identifier = (string) Str::uuid();
        $this->event($account, $identifier, ['occurred_at' => now()->subMinutes(3)]);
        $this->event($account, $identifier, ['event_type' => 'FILLED_IN', 'occurred_at' => now()->subMinutes(2)]);
        $latest = $this->event($account, $identifier, ['event_type' => 'READY_FOR_PROCESSING', 'occurred_at' => now()->subMinute()]);
        $this->event($account, $identifier, ['event_type' => 'HISTORICAL_INCOMPLETE', 'occurred_at' => now()->subMinutes(4)]);
        $before = AllegroOrderImportEvent::all()->map->getRawOriginal()->all();

        $response = $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))->assertOk();

        $row = $response->viewData('importOrders')->sole();
        $this->assertSame($latest->id, $row['latest_event']->id);
        $this->assertSame('processed', $row['state']);
        $this->assertSame(1, substr_count($response->getContent(), 'data-allegro-import-order='));
        $response->assertSee('Ostatnie zdarzenie')->assertSee('Kompletne dane')
            ->assertDontSee('title="HISTORICAL_INCOMPLETE"', false);
        $this->assertSame($before, AllegroOrderImportEvent::all()->map->getRawOriginal()->all());
        $this->assertSame(0, Order::query()->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_a_new_event_updates_the_existing_order_row(): void
    {
        $account = $this->account();
        $identifier = (string) Str::uuid();
        $first = $this->event($account, $identifier);
        $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))
            ->assertOk()->assertSee('title="BOUGHT"', false);

        $latest = $this->event($account, $identifier, ['event_type' => 'FILLED_IN', 'occurred_at' => now()->addSecond()]);
        $response = $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))->assertOk();

        $this->assertSame($latest->id, $response->viewData('importOrders')->sole()['latest_event']->id);
        $this->assertSame(1, substr_count($response->getContent(), 'data-allegro-import-order='));
        $response->assertSee('Formularz dostawy')->assertDontSee('title="BOUGHT"', false);
        $this->assertSame('BOUGHT', $first->fresh()->event_type);
        $this->assertDatabaseCount('allegro_order_import_events', 2);
    }

    public function test_only_ten_most_recently_updated_orders_are_shown_without_pagination(): void
    {
        $account = $this->account();
        $identifiers = [];
        foreach (range(1, 12) as $index) {
            $identifiers[$index] = (string) Str::uuid();
            $this->event($account, $identifiers[$index], ['updated_at' => now()->addMinutes($index)]);
        }
        $this->event($account, $identifiers[1], [
            'event_type' => 'READY_FOR_PROCESSING', 'updated_at' => now()->addMinutes(30),
        ]);

        $response = $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))->assertOk();
        $expected = array_merge([$identifiers[1]], array_reverse(array_slice($identifiers, 3, 9)));
        $actual = $response->viewData('importOrders')->pluck('latest_event.external_order_id')->all();

        $this->assertSame($expected, $actual);
        $this->assertSame(10, substr_count($response->getContent(), 'data-allegro-import-order='));
        $response->assertDontSee($identifiers[2])->assertDontSee($identifiers[3])
            ->assertDontSee('import_page=', false);
        $secondPage = $this->get(route('integrations.allegro.edit', ['tab' => 'orders', 'import_page' => 2]))->assertOk();
        $this->assertSame($expected, $secondPage->viewData('importOrders')->pluck('latest_event.external_order_id')->all());
        $this->assertDatabaseCount('allegro_order_import_events', 13);
    }

    public function test_an_older_unresolved_error_remains_visible_and_can_be_retried(): void
    {
        $account = $this->account();
        $identifier = (string) Str::uuid();
        $failed = $this->event($account, $identifier, [
            'status' => 'failed', 'last_error' => '<script>problem</script>', 'occurred_at' => now()->subMinute(),
        ]);
        $latest = $this->event($account, $identifier, ['event_type' => 'READY_FOR_PROCESSING']);

        $response = $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))->assertOk();
        $row = $response->viewData('importOrders')->sole();
        $this->assertSame($latest->id, $row['latest_event']->id);
        $this->assertSame('failed', $row['state']);
        $this->assertSame($failed->id, $row['retry_event']->id);
        $response->assertSee('Kompletne dane')->assertSee('Błąd')
            ->assertSee('&lt;script&gt;problem&lt;/script&gt;', false)
            ->assertDontSee('<script>problem</script>', false)
            ->assertSee(route('integrations.allegro.orders.retry', $failed), false);

        $this->post(route('integrations.allegro.orders.retry', $failed))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending', $failed->fresh()->status);
        $this->assertSame('processed', $latest->fresh()->status);
        Queue::assertPushed(SyncAllegroOrderJob::class, fn (SyncAllegroOrderJob $job) => $job->eventId === $failed->id);
        $pending = $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))->assertOk();
        $this->assertSame('pending', $pending->viewData('importOrders')->sole()['state']);
        $pending->assertDontSee('Ponów pobranie');
        Http::assertNothingSent();
    }

    public function test_an_older_pending_event_is_not_hidden_by_a_processed_event(): void
    {
        $account = $this->account();
        $identifier = (string) Str::uuid();
        $this->event($account, $identifier, ['status' => 'pending', 'occurred_at' => now()->subMinute()]);
        $latest = $this->event($account, $identifier, ['event_type' => 'FILLED_IN']);

        $response = $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))->assertOk();
        $row = $response->viewData('importOrders')->sole();
        $this->assertSame($latest->id, $row['latest_event']->id);
        $this->assertSame('pending', $row['state']);
        $this->assertNull($row['retry_event']);
    }

    public function test_history_is_scoped_to_the_account_seller_and_environment(): void
    {
        $account = $this->account();
        $otherAccount = $this->account(['account_key' => 'secondary']);
        $identifier = (string) Str::uuid();
        $visible = $this->event($account, $identifier);
        foreach ([['allegro_account_id' => $otherAccount->id], ['seller_user_id' => 'other-seller'], ['environment' => 'sandbox']] as $foreign) {
            $this->event($account, $identifier, array_merge([
                'event_type' => 'BUYER_CANCELLED', 'status' => 'failed', 'last_error' => 'foreign-error',
                'occurred_at' => now()->addMinute(), 'updated_at' => now()->addMinute(),
            ], $foreign));
        }

        $response = $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))->assertOk();
        $row = $response->viewData('importOrders')->sole();
        $this->assertSame($visible->id, $row['latest_event']->id);
        $this->assertSame('processed', $row['state']);
        $this->assertTrue($visible->updated_at->equalTo($row['updated_at']));
        $response->assertDontSee('foreign-error')->assertDontSee('title="BUYER_CANCELLED"', false);
    }

    public function test_empty_history_has_no_pagination_or_log_controls(): void
    {
        $this->account();
        $response = $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))
            ->assertOk()->assertSee('Brak pobranych zamówień.')
            ->assertDontSee('data-allegro-import-order=', false)->assertDontSee('import_page=', false);

        $this->assertCount(0, $response->viewData('importOrders'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function account(array $overrides = []): AllegroAccount
    {
        return AllegroAccount::query()->create(array_replace([
            'account_key' => 'default', 'name' => 'multi-click', 'environment' => 'production',
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'token', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(5), 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'allegro_user_id' => '12345678', 'is_active' => true, 'last_test_status' => 'success',
            'order_import_enabled' => true, 'order_import_identity' => 'production:12345678',
        ], $overrides));
    }

    private function event(AllegroAccount $account, string $identifier, array $overrides = []): AllegroOrderImportEvent
    {
        return AllegroOrderImportEvent::query()->create(array_replace([
            'allegro_account_id' => $account->id, 'environment' => $account->environment,
            'seller_user_id' => $account->allegro_user_id, 'external_event_id' => (string) Str::uuid(),
            'external_order_id' => $identifier, 'event_type' => 'BOUGHT', 'occurred_at' => now(),
            'status' => 'processed', 'updated_at' => now(),
        ], $overrides));
    }
}
