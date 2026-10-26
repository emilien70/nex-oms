<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Jobs\FetchPrestashopOrderMessagesJob;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Modules\Integrations\Prestashop\Models\PrestashopOrderMessage;
use Modules\Integrations\Prestashop\Services\PrestashopOrderMessageService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrestashopOrderMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::swap((new Factory)->preventStrayRequests());
    }

    public function test_public_customer_comments_are_idempotent_and_leave_order_and_finances_untouched(): void
    {
        $link = $this->link();
        $item = $link->order->items()->create(['product_name' => 'Produkt', 'quantity' => 1,
            'unit_price_gross' => '100.00', 'total_price_gross' => '100.00', 'currency' => 'PLN']);
        $before = $link->order->getAttributes();
        $itemBefore = $item->fresh()->getAttributes();
        $linkBefore = $link->fresh()->getAttributes();
        $this->fakeMessages([$this->message()]);

        $this->assertSame(1, $this->sync($link));
        $this->assertSame(1, $this->sync($link));
        $message = PrestashopOrderMessage::query()->sole();
        $this->assertSame(966, $message->external_message_id);
        $this->assertSame(9793, $message->external_customer_id);
        $this->assertSame('Jan Kowalski', $message->author_name);
        $this->assertSame("Prosze o kontakt\nDziekuje", $message->message);
        $this->assertSame('2026-10-25 17:25:41', $message->source_created_at->format('Y-m-d H:i:s'));
        $this->assertSame($before, $link->order->fresh()->getAttributes());
        $this->assertSame($itemBefore, $item->fresh()->getAttributes());
        $this->assertSame($linkBefore, $link->fresh()->getAttributes());
        $this->assertDatabaseCount('order_events', 0);
        $this->assertDatabaseCount('prestashop_order_payments', 0);
        $this->assertDatabaseCount('invoices', 0);
        Queue::assertNothingPushed();
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/messages?')
            && $request['filter[id_order]'] === '[13646]'
            && $request['display'] === '[id,id_order,id_customer,id_employee,message,private,date_add]'
            && $request['limit'] === '0,100'
            && $request->hasHeader('User-Agent', 'NEX-OMS PrestaShop Integration')
            && $request->hasHeader('Accept', 'application/xml')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-api-key:')));
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
    }

    #[DataProvider('excludedMessages')]
    public function test_private_employee_foreign_and_ambiguous_messages_are_not_exposed(array $overrides): void
    {
        $link = $this->link();
        $this->fakeMessages([$this->message($overrides)]);
        $this->assertSame(0, $this->sync($link));
        $this->assertDatabaseCount('prestashop_order_messages', 0);
    }

    public static function excludedMessages(): array
    {
        return [
            'private' => [['private' => '1']],
            'employee' => [['id_employee' => '12']],
            'other buyer' => [['id_customer' => '999']],
            'other order' => [['id_order' => '999']],
            'missing private flag' => [['private' => null]],
            'missing employee flag' => [['id_employee' => null]],
            'anonymous author' => [['id_customer' => '0']],
        ];
    }

    public function test_multiple_comments_are_chronological_escaped_and_visible_above_email_form(): void
    {
        $link = $this->link();
        $this->fakeMessages([
            $this->message(['id' => 967, 'message' => '&lt;img src=x onerror=alert(1)&gt;', 'date_add' => '2026-10-25 18:00:00']),
            $this->message(),
        ]);
        $this->sync($link);
        Http::swap((new Factory)->preventStrayRequests());

        $this->get(route('orders.show', $link->order))
            ->assertOk()->assertSeeInOrder(['data-prestashop-comment-id="966"', 'data-prestashop-comment-id="967"', 'data-order-email-form'], false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertSeeText('Jan Kowalski:')->assertSeeText('25.10.2026 17:25');
        $this->get(route('orders.index'))->assertOk()->assertSee('aria-label="Komentarz kupującego"', false);
        Http::assertNothingSent();
    }

    public function test_polling_refreshes_only_local_comment_fragment_and_not_the_email_composer(): void
    {
        $link = $this->link();
        $before = $this->getJson(route('orders.state', $link->order))->assertOk()->json('buyer_comments_signature');
        $this->fakeMessages([$this->message()]);
        $this->sync($link);
        Http::swap((new Factory)->preventStrayRequests());
        $response = $this->getJson(route('orders.state', $link->order).'?buyer_comments_signature='.$before)->assertOk();
        $this->assertStringContainsString('data-prestashop-comment-id="966"', $response->json('fragments.buyer-comments'));
        $this->assertStringNotContainsString('data-order-email-form', $response->json('fragments.buyer-comments'));
        $this->getJson(route('orders.state', $link->order).'?buyer_comments_signature='.$response->json('buyer_comments_signature'))
            ->assertOk()->assertJsonMissingPath('fragments.buyer-comments');
        Http::assertNothingSent();
    }

    public function test_list_signature_changes_when_a_comment_arrives_without_touching_the_order(): void
    {
        $link = $this->link();
        $before = $this->get(route('orders.index'))->assertOk()->viewData('listSignature');
        $this->fakeMessages([$this->message()]);
        $this->sync($link);
        $after = $this->get(route('orders.index'))->assertOk()->viewData('listSignature');
        $this->assertNotSame($before, $after);
    }

    public function test_duplicate_source_id_updates_one_comment_and_empty_results_do_not_erase_history(): void
    {
        $link = $this->link();
        $this->fakeMessages([$this->message(), $this->message()]);
        $this->sync($link);
        $id = PrestashopOrderMessage::query()->sole()->id;
        $this->fakeMessages([$this->message(['message' => 'Zmieniona tresc'])]);
        $this->sync($link);
        $this->assertSame($id, PrestashopOrderMessage::query()->sole()->id);
        $this->assertSame('Zmieniona tresc', PrestashopOrderMessage::query()->sole()->message);
        $this->fakeMessages([]);
        $this->sync($link);
        $this->assertDatabaseCount('prestashop_order_messages', 1);
    }

    public function test_pagination_fetches_multiple_pages_for_only_one_order(): void
    {
        $link = $this->link();
        $rows = [];
        for ($i = 1; $i <= 101; $i++) {
            $rows[] = $this->message(['id' => $i]);
        }
        $this->fakeMessages($rows);
        $this->assertSame(101, $this->sync($link));
        $this->assertDatabaseCount('prestashop_order_messages', 101);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/messages?') && $request['limit'] === '100,100');
    }

    public function test_limit_overflow_does_not_save_a_partial_page_or_fetch_all_orders(): void
    {
        $link = $this->link();
        $rows = [];
        for ($i = 1; $i <= 300; $i++) {
            $rows[] = $this->message(['id' => $i]);
        }
        $this->fakeMessages($rows);
        try {
            $this->sync($link);
            $this->fail('Expected bounded fetch rejection.');
        } catch (PrestashopApiException) {
            $this->assertDatabaseCount('prestashop_order_messages', 0);
            Http::assertSentCount(4);
        }
    }

    #[DataProvider('invalidMessages')]
    public function test_invalid_public_comment_never_creates_a_partial_batch(array $overrides): void
    {
        $link = $this->link();
        $this->fakeMessages([$this->message(), $this->message(array_merge(['id' => 967], $overrides))]);
        try {
            $this->sync($link);
            $this->fail('Expected invalid comment rejection.');
        } catch (PrestashopApiException) {
            $this->assertDatabaseCount('prestashop_order_messages', 0);
        }
    }

    public static function invalidMessages(): array
    {
        return [
            'missing id' => [['id' => null]],
            'invalid date' => [['date_add' => '2026-02-31 12:00:00']],
            'empty text' => [['message' => '   ']],
            'oversized text' => [['message' => str_repeat('x', 65536)]],
        ];
    }

    public function test_rollback_does_not_enqueue_comments(): void
    {
        DB::beginTransaction();
        app(PrestashopOrderMessageService::class)->enqueue(123);
        DB::rollBack();
        Queue::assertNothingPushed();
    }

    public function test_permission_error_preserves_history_and_does_not_fail_the_import_job(): void
    {
        $link = $this->link();
        $this->fakeMessages([$this->message()]);
        $this->sync($link);
        $this->fakeMessages([], 403);
        Log::spy();
        (new FetchPrestashopOrderMessagesJob($link->id))->handle(app(PrestashopOrderMessageService::class));
        $this->assertDatabaseCount('prestashop_order_messages', 1);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_transient_error_is_retried_only_by_the_comment_job(): void
    {
        $link = $this->link();
        $this->fakeMessages([], 503);
        $job = new FetchPrestashopOrderMessagesJob($link->id);
        $this->assertSame('integrations', $job->queue);
        $this->assertSame(4, $job->tries);
        $this->expectException(PrestashopApiException::class);
        $job->handle(app(PrestashopOrderMessageService::class));
    }

    public function test_dispatch_failure_is_isolated_from_a_committed_import(): void
    {
        Log::spy();
        Queue::shouldReceive('push')->andThrow(new \RuntimeException('queue unavailable'));
        app(PrestashopOrderMessageService::class)->enqueue(123);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_changed_account_during_http_cannot_save_comments_to_a_new_identity(): void
    {
        $link = $this->link();
        $this->fakeMessages([$this->message()], onMessages: function () use ($link): void {
            $link->account->update(['base_url' => 'https://other.example.test']);
        });
        try {
            $this->sync($link);
            $this->fail('Expected identity rejection.');
        } catch (PrestashopApiException) {
            $this->assertDatabaseCount('prestashop_order_messages', 0);
        }
    }

    public function test_disabled_or_deleted_orders_are_not_fetched(): void
    {
        $link = $this->link();
        $link->account->update(['is_active' => false]);
        $this->assertSame(0, $this->sync($link));
        $link->account->update(['is_active' => true]);
        $link->order->delete();
        $this->assertSame(0, $this->sync($link));
        Http::assertNothingSent();
    }

    public function test_database_unique_constraint_and_null_on_delete_preserve_comment_history(): void
    {
        $link = $this->link();
        $this->fakeMessages([$this->message()]);
        $this->sync($link);
        $row = PrestashopOrderMessage::query()->sole()->getAttributes();
        unset($row['id']);
        DB::table('prestashop_order_messages')->insertOrIgnore($row);
        $this->assertDatabaseCount('prestashop_order_messages', 1);
        $link->delete();
        $this->assertNull(PrestashopOrderMessage::query()->sole()->prestashop_order_link_id);
        $this->assertTrue(Order::query()->whereKey($link->order_id)->exists());
    }

    public function test_targeted_command_validates_oms_id_and_fetches_no_other_order(): void
    {
        $link = $this->link();
        $this->artisan('prestashop:order-comments', ['order' => 'not-id'])->assertFailed();
        $this->artisan('prestashop:order-comments', ['order' => '999999'])->assertFailed();
        Http::assertNothingSent();
        $this->fakeMessages([$this->message()]);
        $this->artisan('prestashop:order-comments', ['order' => $link->order_id])->assertSuccessful();
        $this->assertDatabaseCount('prestashop_order_messages', 1);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_migration_refuses_to_remove_collected_history(): void
    {
        $link = $this->link();
        $this->fakeMessages([$this->message()]);
        $this->sync($link);
        $migration = require database_path('migrations/2026_10_26_020000_create_prestashop_order_messages_table.php');
        try {
            $migration->down();
            $this->fail('History must survive rollback.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('prestashop_order_messages', 1);
        }
    }

    public function test_mysql_schema_uses_short_explicit_names_and_preserves_orphaned_history(): void
    {
        $original = Schema::getFacadeRoot();
        $connection = new class(fn () => throw new \RuntimeException('No real MySQL connection allowed.'), 'schema_preview') extends MySqlConnection
        {
            public function isMaria()
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.46';
            }
        };
        Schema::swap($connection->getSchemaBuilder());
        try {
            $migration = require database_path('migrations/2026_10_26_020000_create_prestashop_order_messages_table.php');
            $sql = implode("\n", array_column($connection->pretend(fn () => $migration->up()), 'query'));
        } finally {
            Schema::swap($original);
        }
        $this->assertStringContainsString('`ps_order_messages_link_fk`', $sql);
        $this->assertStringContainsString('on delete set null', $sql);
        $this->assertStringContainsString('`ps_order_messages_external_unique`(`prestashop_order_link_id`, `external_message_id`)', $sql);
        preg_match_all('/(?:constraint|unique|index) `([^`]+)`/', $sql, $matches);
        foreach ($matches[1] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name));
        }
    }

    private function link(): PrestashopOrderLink
    {
        $account = PrestashopAccount::query()->create([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl', 'base_url' => 'https://shop.example.test',
            'api_key' => 'test-api-key', 'is_active' => true,
        ]);
        $order = Order::query()->create(['source' => 'prestashop', 'external_id' => '13646',
            'status' => 'new', 'shipping_name' => 'Odbiorca prezentu', 'notes' => 'Numery seryjne sprzedawcy',
            'total_gross' => '100.00', 'paid_amount' => '100.00', 'currency' => 'PLN', 'payment_status' => 'paid']);

        return PrestashopOrderLink::query()->create(['prestashop_account_id' => $account->id,
            'order_id' => $order->id, 'external_order_id' => 13646, 'external_reference' => '13646',
            'source_hash' => str_repeat('a', 64), 'imported_at' => now()]);
    }

    private function message(array $overrides = []): array
    {
        return array_merge(['id' => 966, 'id_order' => 13646, 'id_customer' => 9793,
            'id_employee' => 0, 'private' => 0, 'date_add' => '2026-10-25 17:25:41',
            'message' => "Prosze o kontakt\nDziekuje"], $overrides);
    }

    private function fakeMessages(array $rows, int $status = 200, ?\Closure $onMessages = null): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(function (Request $request) use ($rows, $status, $onMessages) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/api/orders/13646' => Http::response(['orders' => [['id' => 13646, 'id_customer' => 9793]]]),
                '/api/customers/9793' => Http::response(['customer' => ['id' => 9793, 'firstname' => 'Jan', 'lastname' => 'Kowalski']]),
                '/api/messages' => (function () use ($request, $rows, $status, $onMessages) {
                    $onMessages?->__invoke();
                    $xml = new \SimpleXMLElement('<prestashop><messages/></prestashop>');
                    $offset = (int) explode(',', $request['limit'])[0];
                    foreach (array_slice($rows, $offset, 100) as $row) {
                        $node = $xml->messages->addChild('message');
                        foreach ($row as $key => $value) {
                            if ($value !== null) {
                                $node->addChild($key, htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                            }
                        }
                    }

                    return Http::response($xml->asXML(), $status, ['Content-Type' => 'application/xml']);
                })(),
                default => throw new \RuntimeException('Unexpected request: '.$request->url()),
            };
        });
    }

    private function sync(PrestashopOrderLink $link): int
    {
        return app(PrestashopOrderMessageService::class)->sync($link->id);
    }
}
