<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ReturnStatusSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Jobs\ProcessAllegroCommissionRefundJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroCommissionRefundClaim;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Services\AllegroCommissionRefundService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AllegroOrderFixture;
use Tests\TestCase;

class AllegroCommissionRefundsTest extends TestCase
{
    use RefreshDatabase;

    private array $checkout;

    private array $remote = [];

    private array $writes = [];

    private bool $timeout = false;

    private bool $acceptedDuringTimeout = false;

    private int $postStatus = 201;

    private int $post401 = 0;

    private bool $malformedPost = false;

    private bool $badPage = false;

    private ?string $rejectedLine = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(12, 0));
        $this->checkout = AllegroOrderFixture::payload();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/auth/oauth/token')) {
                return Http::response(['access_token' => 'renewed', 'refresh_token' => 'renewed-refresh', 'expires_in' => 3600]);
            }
            if (str_contains($request->url(), '/order/checkout-forms/')) {
                return Http::response($this->checkout);
            }
            if (str_contains($request->url(), '/order/refund-claims')) {
                if ($request->method() === 'POST') {
                    $this->writes[] = $request->data();
                    if ($request->data()['lineItem']['id'] === $this->rejectedLine) {
                        return Http::response([], 422);
                    }
                    if ($this->post401 > 0) {
                        $this->post401--;

                        return Http::response([], 401);
                    }
                    if ($this->postStatus !== 201) {
                        return Http::response(['errors' => [['message' => 'PRIVATE-DETAILS-AND-TOKEN']]], $this->postStatus);
                    }
                    $row = $this->remoteClaim($request->data());
                    if (! $this->timeout || $this->acceptedDuringTimeout) {
                        $this->remote[] = $row;
                    }
                    if ($this->timeout) {
                        throw new ConnectionException('PRIVATE-DETAILS-AND-TOKEN');
                    }

                    return Http::response($this->malformedPost ? [] : ['id' => $row['id']], 201);
                }
                $path = parse_url($request->url(), PHP_URL_PATH);
                if (str_ends_with($path, '/refund-claims')) {
                    parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                    $rows = array_values(array_filter($this->remote, fn ($row) => $row['buyer']['id'] === ($query['buyer_id'] ?? '')));
                    $rows = array_slice($rows, (int) ($query['offset'] ?? 0), 100);

                    return Http::response(['refundClaims' => $rows, 'count' => $this->badPage ? count($rows) + 1 : count($rows)]);
                }
                $id = basename($path);
                $row = collect($this->remote)->firstWhere('id', $id);

                return Http::response($row ?? [], $row ? 200 : 404);
            }
            throw new \RuntimeException('Unexpected fake request');
        });
    }

    protected function tearDown(): void
    {
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('NEX-OMS_device/v1.0.0 (+https://multi-click.pl/nex-oms/doc.html)', $request->header('User-Agent')[0] ?? null);
        }
        parent::tearDown();
    }

    public function test_button_is_to_the_right_of_payment_refund_and_does_not_require_payment_write_gate(): void
    {
        $return = $this->returnRecord($this->account());
        $this->get(route('returns.show', $return))->assertOk()->assertSeeInOrder(['Zwróć wpłatę', 'Zwrot Prowizji'])
            ->assertSee(route('returns.commission-refund.create', $return), false);
        $this->get(route('returns.commission-refund.create', $return))->assertOk()->assertSee('Wyślij wniosek')->assertSee('tylko jeden wniosek');
        $this->assertDatabaseCount('allegro_commission_refund_claims', 0);
        $this->assertCount(0, $this->writes);
    }

    public function test_confirmation_queues_and_posts_exact_allegro_line_and_quantity_only_once(): void
    {
        $return = $this->returnRecord($this->account());
        $before = $return->order->getAttributes();
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $this->service()->submit($return, $data, $quote);
        Queue::assertPushed(ProcessAllegroCommissionRefundJob::class, 1);
        $claim = AllegroCommissionRefundClaim::query()->sole();
        $this->service()->process($claim->id);
        $this->service()->process($claim->id);
        $this->assertCount(1, $this->writes);
        $this->assertSame(['lineItem' => ['id' => $this->checkout['lineItems'][0]['id']], 'quantity' => 2], $this->writes[0]);
        $this->assertSame('known', $claim->fresh()->state);
        $this->assertSame('IN_PROGRESS', $claim->fresh()->status);
        $this->assertSame($before, $return->order->fresh()->getAttributes());
        foreach (['invoices', 'allegro_payment_refunds', 'order_return_refund_allocations', 'allegro_refund_commands'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->get(route('returns.show', $return))->assertOk()->assertSee('Wniosek w trakcie rozpatrywania');
    }

    public function test_actual_http_form_requires_same_session_quote_and_explicit_confirmation(): void
    {
        $this->withSession(['commission' => 'test'])->withCookie(config('session.cookie'), session()->getId());
        $return = $this->returnRecord($this->account());
        $response = $this->get(route('returns.commission-refund.create', $return))->assertOk();
        $quote = $response->viewData('quote');
        [$data] = $this->submission($return);
        $data['quote_token'] = $quote['token'];
        $this->post(route('returns.commission-refund.store', $return), $data)->assertSessionHasErrors('confirmed');
        $data['confirmed'] = 1;
        $this->post(route('returns.commission-refund.store', $return), $data)->assertRedirect(route('returns.show', $return));
        $this->assertDatabaseCount('allegro_commission_refund_claims', 1);
        $this->assertCount(0, $this->writes);
    }

    public function test_another_return_for_the_same_line_does_not_create_second_claim(): void
    {
        $account = $this->account();
        $one = $this->returnRecord($account);
        $two = $this->returnRecord($account, $one->order);
        [$data, $quote] = $this->submission($one);
        $this->service()->submit($one, $data, $quote);
        [$data, $quote] = $this->submission($two);
        $this->service()->submit($two, $data, $quote);
        $this->assertDatabaseCount('allegro_commission_refund_claims', 1);
        $this->service()->process(AllegroCommissionRefundClaim::query()->sole()->id);
        $this->assertCount(1, $this->writes);
        $this->get(route('returns.show', $two))->assertOk()->assertSee('Zwroty prowizji Allegro');
    }

    public function test_existing_automatic_claim_is_recognized_and_no_post_is_sent(): void
    {
        $return = $this->returnRecord($this->account());
        [$data, $quote] = $this->submission($return);
        $row = $this->remoteClaim(['lineItem' => ['id' => $this->checkout['lineItems'][0]['id']], 'quantity' => 1]);
        $row['type'] = 'AUTOMATIC';
        $row['status'] = 'GRANTED';
        $this->remote = [$row];
        $this->service()->submit($return, $data, $quote);
        $claim = AllegroCommissionRefundClaim::query()->sole();
        $this->service()->process($claim->id);
        $this->assertSame('GRANTED', $claim->fresh()->status);
        $this->assertSame('AUTOMATIC', $claim->fresh()->claim_type);
        $this->assertSame(1, $claim->fresh()->claimed_quantity);
        $this->assertNull($claim->fresh()->started_at);
        $this->assertCount(0, $this->writes);
        $this->get(route('returns.commission-refund.create', $return))->assertOk()->assertSee('Automatyczny wniosek Allegro')->assertDontSee('Wyślij wniosek');
    }

    public function test_timeout_accepted_by_allegro_is_reconciled_by_exact_line_without_resending(): void
    {
        $return = $this->returnRecord($this->account());
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $this->timeout = true;
        $this->acceptedDuringTimeout = true;
        $claim = AllegroCommissionRefundClaim::query()->sole();
        $this->service()->process($claim->id);
        $this->assertSame('uncertain', $claim->fresh()->state);
        $this->service()->process($claim->id);
        $this->assertSame('known', $claim->fresh()->state);
        $this->assertCount(1, $this->writes);
        $this->assertStringNotContainsString('PRIVATE', json_encode(DB::table('integration_api_logs')->get()));
    }

    public function test_timeout_without_a_remote_claim_never_reposts_even_on_repeated_job(): void
    {
        $return = $this->returnRecord($this->account());
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $this->timeout = true;
        $claim = AllegroCommissionRefundClaim::query()->sole();
        $this->service()->process($claim->id);
        $this->service()->process($claim->id);
        $this->service()->process($claim->id);
        $this->assertSame('uncertain', $claim->fresh()->state);
        $this->assertCount(1, $this->writes);
    }

    public function test_401_refresh_reuses_the_same_line_and_quantity(): void
    {
        $return = $this->returnRecord($this->account());
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $this->post401 = 1;
        $this->service()->process(AllegroCommissionRefundClaim::query()->sole()->id);
        $this->assertCount(2, $this->writes);
        $this->assertSame($this->writes[0], $this->writes[1]);
    }

    #[DataProvider('httpErrors')]
    public function test_rejections_and_5xx_never_mean_granted_or_trigger_post_retry(int $code, string $state): void
    {
        $return = $this->returnRecord($this->account());
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $this->postStatus = $code;
        $claim = AllegroCommissionRefundClaim::query()->sole();
        $this->service()->process($claim->id);
        $this->service()->process($claim->id);
        $this->assertSame($state, $claim->fresh()->state);
        $this->assertNull($claim->fresh()->status);
        $this->assertCount(1, $this->writes);
        $this->assertStringNotContainsString('PRIVATE', json_encode(DB::table('integration_api_logs')->get()));
    }

    public static function httpErrors(): array
    {
        return [[422, 'failed'], [403, 'failed'], [503, 'uncertain'], [429, 'uncertain']];
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_or_missing_items_cannot_queue_a_claim(int $quantity): void
    {
        $return = $this->returnRecord($this->account());
        [$data, $quote] = $this->submission($return);
        $data['items'][array_key_first($data['items'])]['quantity'] = $quantity;
        $this->expectException(ValidationException::class);
        $this->service()->submit($return, $data, $quote);
    }

    public static function invalidQuantities(): array
    {
        return [[0], [-1], [3], [1000001]];
    }

    #[DataProvider('preflightChanges')]
    public function test_changed_or_unsafe_context_prevents_post(string $change): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        match ($change) {
            'identity' => $account->update(['environment' => 'sandbox']),
            'inactive' => $account->update(['is_active' => false]),
            'credentials' => $account->update(['client_id' => 'other-client']),
            'return' => $return->increment('lock_version'),
            'mapping' => $return->items()->update(['order_item_id' => null]),
            'source' => $this->checkout['buyer']['id'] = 'different-buyer',
            'expired' => $this->travel(6)->minutes(),
        };
        $claim = AllegroCommissionRefundClaim::query()->sole();
        $this->service()->process($claim->id);
        $this->assertSame('not_sent', $claim->fresh()->state);
        $this->assertNull($claim->fresh()->started_at);
        $this->assertCount(0, $this->writes);
    }

    public static function preflightChanges(): array
    {
        return array_map(fn ($v) => [$v], ['identity', 'inactive', 'credentials', 'return', 'mapping', 'source', 'expired']);
    }

    public function test_wrong_session_or_expired_quote_is_rejected_before_api_read(): void
    {
        $return = $this->returnRecord($this->account());
        [$data, $quote] = $this->submission($return);
        $quote['token'] = (string) Str::uuid();
        $this->expectException(ValidationException::class);
        $this->service()->submit($return, $data, $quote);
    }

    public function test_unpaid_or_cash_on_delivery_does_not_require_payment_scopes(): void
    {
        $return = $this->returnRecord($this->account());
        $this->checkout['payment'] = ['type' => 'CASH_ON_DELIVERY'];
        $this->checkout['status'] = 'BOUGHT';
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $this->service()->process(AllegroCommissionRefundClaim::query()->sole()->id);
        $this->assertCount(1, $this->writes);
    }

    public function test_truncated_page_and_duplicate_line_claims_block_unsafe_read(): void
    {
        $return = $this->returnRecord($this->account());
        $this->badPage = true;
        $this->expectException(ValidationException::class);
        $this->service()->context($return);
    }

    public function test_all_pages_are_read_and_exact_line_is_used_not_offer_alone(): void
    {
        $return = $this->returnRecord($this->account());
        for ($i = 0; $i < 100; $i++) {
            $row = $this->remoteClaim(['lineItem' => ['id' => (string) Str::uuid()], 'quantity' => 1]);
            $this->remote[] = $row;
        }
        $context = $this->service()->context($return);
        $this->assertCount(100, $context['known']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'offset=100'));
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $this->service()->process(AllegroCommissionRefundClaim::query()->sole()->id);
        $this->assertCount(1, $this->writes);
    }

    public function test_crashed_sending_is_recovered_by_read_not_another_post(): void
    {
        $return = $this->returnRecord($this->account());
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $claim = AllegroCommissionRefundClaim::query()->sole();
        $claim->update(['state' => 'sending', 'started_at' => now()->subMinutes(6)]);
        $this->service()->dispatchDue();
        $this->assertSame('uncertain', $claim->fresh()->state);
        $this->service()->process($claim->id);
        $this->assertCount(0, $this->writes);
    }

    public function test_known_claim_status_is_updated_and_history_survives_parent_deletion(): void
    {
        $return = $this->returnRecord($this->account());
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $claim = AllegroCommissionRefundClaim::query()->sole();
        $this->service()->process($claim->id);
        $this->remote[0]['status'] = 'GRANTED';
        $this->service()->refresh($claim->fresh());
        $this->assertSame('GRANTED', $claim->fresh()->status);
        $count = $return->events()->count();
        $this->service()->refresh($claim->fresh());
        $this->assertSame($count, $return->events()->count());
        $return->delete();
        $return->order->forceDelete();
        $this->assertNull($claim->fresh()->order_return_id);
        $this->assertNull($claim->fresh()->order_id);
        $this->assertSame($return->id, $claim->fresh()->return_number);
    }

    public function test_sent_quantity_and_identity_cannot_be_edited(): void
    {
        $return = $this->returnRecord($this->account());
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $claim = AllegroCommissionRefundClaim::query()->sole();
        $this->service()->process($claim->id);
        $this->expectException(\LogicException::class);
        $claim->fresh()->update(['quantity' => 1]);
    }

    public function test_malformed_201_is_uncertain_then_reconciled_without_repost(): void
    {
        $return = $this->returnRecord($this->account());
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $this->malformedPost = true;
        $claim = AllegroCommissionRefundClaim::query()->sole();
        $this->service()->process($claim->id);
        $this->assertSame('uncertain', $claim->fresh()->state);
        $this->service()->process($claim->id);
        $this->assertSame('known', $claim->fresh()->state);
        $this->assertCount(1, $this->writes);
    }

    public function test_duplicate_remote_line_cannot_be_treated_as_no_claim(): void
    {
        $return = $this->returnRecord($this->account());
        $payload = ['lineItem' => ['id' => $this->checkout['lineItems'][0]['id']], 'quantity' => 1];
        $this->remote = [$this->remoteClaim($payload), $this->remoteClaim($payload)];
        $this->expectException(ValidationException::class);
        $this->service()->context($return);
    }

    public function test_wrong_line_in_status_response_cannot_overwrite_previous_fact(): void
    {
        $return = $this->returnRecord($this->account());
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $claim = AllegroCommissionRefundClaim::query()->sole();
        $this->service()->process($claim->id);
        $this->service()->refresh($claim->fresh());
        $this->remote[0]['status'] = 'GRANTED';
        $this->remote[0]['lineItem']['id'] = (string) Str::uuid();
        $this->service()->refresh($claim->fresh());
        $this->assertSame('IN_PROGRESS', $claim->fresh()->status);
        $this->assertNotNull($claim->fresh()->error);
    }

    public function test_multiple_products_keep_each_result_without_resending_successful_lines(): void
    {
        $return = $this->returnRecord($this->account());
        $lineId = (string) Str::uuid();
        $this->checkout['lineItems'][] = ['id' => $lineId, 'quantity' => 1, 'offer' => ['id' => '12345678901']];
        $orderItem = $return->order->items()->create(['external_id' => $lineId, 'offer_id' => '12345678901', 'product_name' => 'Drugi produkt',
            'quantity' => 1, 'unit_price_gross' => '10.00', 'total_price_gross' => '10.00', 'currency' => 'PLN']);
        $item = $return->items()->create(['order_item_id' => $orderItem->id, 'position' => 2, 'offer_id' => '12345678901',
            'name' => 'Drugi produkt', 'quantity' => 1, 'unit_price' => '10.00', 'total_value' => '10.00', 'currency' => 'PLN', 'reason' => 'MISTAKE']);
        $context = $this->service()->context($return);
        $token = (string) Str::uuid();
        $data = ['quote_token' => $token, 'items' => [$return->items()->orderBy('position')->first()->id => ['selected' => 1, 'quantity' => 2],
            $item->id => ['selected' => 1, 'quantity' => 1]]];
        $quote = ['token' => $token, 'hash' => $context['hash'], 'expires' => now()->addMinutes(5)->timestamp];
        $this->service()->submit($return, $data, $quote);
        $this->rejectedLine = $lineId;
        foreach (AllegroCommissionRefundClaim::query()->get() as $claim) {
            $this->service()->process($claim->id);
            $this->service()->process($claim->id);
        }
        $this->assertCount(2, $this->writes);
        $this->assertSame(1, AllegroCommissionRefundClaim::query()->where('state', 'known')->count());
        $this->assertSame(1, AllegroCommissionRefundClaim::query()->where('state', 'failed')->count());
    }

    public function test_request_not_sent_can_be_confirmed_again_but_started_request_cannot(): void
    {
        $return = $this->returnRecord($this->account());
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $claim = AllegroCommissionRefundClaim::query()->sole();
        $this->travel(6)->minutes();
        $this->service()->process($claim->id);
        $this->assertSame('not_sent', $claim->fresh()->state);
        [$data, $quote] = $this->submission($return);
        $this->service()->submit($return, $data, $quote);
        $this->service()->process($claim->id);
        $this->assertSame('submitted', $claim->fresh()->state);
        $this->assertDatabaseCount('allegro_commission_refund_claims', 1);
        $this->assertCount(1, $this->writes);
    }

    private function account(): AllegroAccount
    {
        return AllegroAccount::query()->create(['account_key' => 'default', 'name' => 'multi-click', 'environment' => 'production',
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(5), 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'allegro_user_id' => 'seller-1', 'is_active' => true, 'last_test_status' => 'success']);
    }

    private function returnRecord(AllegroAccount $account, ?Order $order = null): OrderReturn
    {
        if (! $order) {
            $order = Order::query()->create(['source' => 'allegro', 'status' => 'new', 'currency' => 'PLN', 'total_gross' => '100.03', 'paid_amount' => '0.00']);
            AllegroOrderLink::query()->create(['allegro_account_id' => $account->id, 'order_id' => $order->id, 'environment' => 'production', 'seller_user_id' => 'seller-1',
                'account_name' => 'multi-click', 'external_order_id' => AllegroOrderFixture::ORDER_ID, 'external_status' => 'READY_FOR_PROCESSING',
                'source_hash' => hash('sha256', 'order'), 'imported_at' => now(), 'import_state' => 'complete']);
            $order->items()->create(['external_id' => $this->checkout['lineItems'][0]['id'], 'offer_id' => '12345678901', 'product_name' => 'Produkt',
                'quantity' => 3, 'unit_price_gross' => '30.01', 'total_price_gross' => '90.03', 'currency' => 'PLN']);
        }
        $return = OrderReturn::query()->create(['source' => 'allegro', 'creation_method' => 'manual', 'order_id' => $order->id,
            'local_status_id' => ReturnStatusSetting::initial()->id, 'currency' => 'PLN', 'total_value' => '60.02', 'buyer_name' => 'Jan Testowy', 'submitted_at' => now()]);
        $return->items()->create(['order_item_id' => $order->items()->sole()->id, 'position' => 1, 'offer_id' => '12345678901',
            'name' => 'Produkt', 'quantity' => 2, 'unit_price' => '30.01', 'total_value' => '60.02', 'currency' => 'PLN', 'reason' => 'MISTAKE']);

        return $return->fresh();
    }

    private function submission(OrderReturn $return): array
    {
        $context = $this->service()->context($return);
        $token = (string) Str::uuid();

        return [['quote_token' => $token, 'items' => [$return->items()->sole()->id => ['selected' => 1, 'quantity' => 2]]],
            ['token' => $token, 'hash' => $context['hash'], 'expires' => now()->addMinutes(5)->timestamp]];
    }

    private function remoteClaim(array $payload): array
    {
        return ['id' => (string) Str::uuid(), 'quantity' => $payload['quantity'], 'status' => 'IN_PROGRESS', 'type' => 'MANUAL',
            'buyer' => ['id' => $this->checkout['buyer']['id']], 'createdAt' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'lineItem' => ['id' => $payload['lineItem']['id'], 'quantity' => 3, 'offer' => ['id' => '12345678901']]];
    }

    private function service(): AllegroCommissionRefundService
    {
        return app(AllegroCommissionRefundService::class);
    }
}
