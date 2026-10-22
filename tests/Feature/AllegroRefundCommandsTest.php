<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ReturnStatusSetting;
use App\Services\OrderReturnRefundService;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Jobs\ProcessAllegroRefundCommandJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Models\AllegroPaymentRefund;
use Modules\Integrations\Allegro\Models\AllegroRefundCommand;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroOAuthService;
use Modules\Integrations\Allegro\Services\AllegroRefundCommandService;
use Modules\Integrations\Allegro\Services\AllegroRefundContextService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AllegroOrderFixture;
use Tests\TestCase;

class AllegroRefundCommandsTest extends TestCase
{
    use RefreshDatabase;

    private array $checkout;

    private array $refunds = [];

    private array $writes = [];

    private string $responseStatus = 'NEW';

    private ?int $httpError = null;

    private bool $timeout = false;

    private array $oauthQueries = [];

    private ?Closure $responseOverride = null;

    private int $post401 = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(12, 0));
        Queue::fake();
        Http::preventStrayRequests();
        $this->checkout = AllegroOrderFixture::payload();
        $this->checkout['surcharges'] = [];
        $this->checkout['payment']['paidAmount']['amount'] = '100.03';
        Http::fake(function ($request) {
            if ($this->responseOverride && ($response = ($this->responseOverride)($request)) !== null) {
                return $response;
            }
            if (str_contains($request->url(), '/auth/oauth/token')) {
                return Http::response(['access_token' => 'refreshed-access', 'refresh_token' => 'refreshed-refresh', 'expires_in' => 3600]);
            }
            if (str_contains($request->url(), '/auth/oauth/device')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->oauthQueries[] = $query;

                return Http::response(['device_code' => 'device', 'user_code' => 'code', 'verification_uri' => 'https://allegro.pl', 'expires_in' => 600, 'interval' => 5]);
            }
            if (str_contains($request->url(), '/order/checkout-forms/')) {
                return Http::response($this->checkout);
            }
            if (str_contains($request->url(), '/payments/refunds')) {
                if ($request->method() === 'POST') {
                    $this->writes[] = $request->data();
                    if ($this->post401 > 0) {
                        $this->post401--;

                        return Http::response([], 401);
                    }
                    if ($this->timeout) {
                        throw new ConnectionException('PRIVATE-COMMENT-AND-TOKEN');
                    }
                    if ($this->httpError) {
                        return Http::response(['errors' => [['message' => 'PRIVATE-COMMENT-AND-TOKEN']]], $this->httpError);
                    }

                    return Http::response($this->refund($request->data(), $this->responseStatus));
                }
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $rows = isset($query['id']) ? array_values(array_filter($this->refunds, fn ($row) => $row['id'] === $query['id'])) : $this->refunds;

                return Http::response(['refunds' => array_slice($rows, (int) ($query['offset'] ?? 0), 100), 'count' => count(array_slice($rows, (int) ($query['offset'] ?? 0), 100)), 'totalCount' => count($rows)]);
            }
            throw new \RuntimeException('Unexpected fake URL');
        });
    }

    protected function tearDown(): void
    {
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('NEX-OMS_device/v1.0.0 (+https://multi-click.pl/nex-oms/doc.html)', $request->header('User-Agent')[0] ?? null);
        }
        parent::tearDown();
    }

    public function test_default_is_off_and_scope_is_optional_with_no_network_on_settings_save(): void
    {
        $account = $this->account(false);
        $this->assertFalse($account->refund_write_enabled);
        $this->assertFalse($account->refund_write_scope_requested);
        $this->assertNotContains(AllegroAccount::PAYMENTS_WRITE_SCOPE, AllegroAccount::REQUIRED_SCOPES);
        $data = $this->settings($account);
        $data['refund_write_scope_requested'] = 1;
        $data['refund_write_enabled'] = 0;
        $this->put(route('integrations.allegro.refund-commands.settings'), $data)->assertRedirect();
        $this->assertTrue($account->fresh()->refund_write_scope_requested);
        $this->assertFalse($account->fresh()->refund_write_enabled);
        $this->assertFalse($account->fresh()->refunds_enabled);
        Http::assertNothingSent();
    }

    public function test_missing_write_scope_stale_settings_and_stale_identity_cannot_enable_writes(): void
    {
        $account = $this->account(false);
        $data = $this->settings($account);
        $this->put(route('integrations.allegro.refund-commands.settings'), $data)->assertSessionHasErrors('refund_write');
        $data['configuration_revision'] = 99;
        $this->put(route('integrations.allegro.refund-commands.settings'), $data)->assertSessionHasErrors('refund_write');
        $data['configuration_revision'] = 0;
        $data['identity'] = 'sandbox:seller-1';
        $this->put(route('integrations.allegro.refund-commands.settings'), $data)->assertSessionHasErrors('refund_write');
        Http::assertNothingSent();
    }

    public function test_oauth_requests_write_only_after_explicit_opt_in(): void
    {
        config(['services.allegro.scopes' => config('services.allegro.scopes').' '.AllegroAccount::PAYMENTS_WRITE_SCOPE]);
        $account = $this->account(false);
        app(AllegroOAuthService::class)->start($account);
        $account->update(['refund_write_scope_requested' => true]);
        app(AllegroOAuthService::class)->start($account);
        $this->assertStringNotContainsString(AllegroAccount::PAYMENTS_WRITE_SCOPE, $this->oauthQueries[0]['scope']);
        $this->assertStringContainsString(AllegroAccount::PAYMENTS_WRITE_SCOPE, $this->oauthQueries[1]['scope']);
    }

    public function test_full_ui_confirmation_and_duplicate_click_queue_one_write_with_delivery_default_off(): void
    {
        $this->withSession(['refund_flow' => 'test'])->withCookie(config('session.cookie'), session()->getId());
        $return = $this->returnRecord($this->account());
        $this->get(route('returns.refund-payment.create', $return))->assertOk()->assertSee('Zwróć również koszt dostawy')
            ->assertDontSee('id="includeDelivery" checked', false)->assertSee('value="60.02"', false);
        $fresh = app(AllegroRefundContextService::class)->fresh($return);
        $this->post(route('returns.refund-payment.prepare', $return), $this->data($return, $fresh))->assertRedirect();
        $command = AllegroRefundCommand::query()->sole();
        $this->assertSame('0.00', $command->delivery_value);
        $this->assertArrayNotHasKey('delivery', $command->payload);
        $this->assertSame('60.02', $command->total_value);
        $this->get(route('returns.refund-payment.confirmation', [$return, $command]))->assertOk()->assertSee('Zleć zwrot 60.02 PLN');
        $this->post(route('returns.refund-payment.confirm', [$return, $command]), ['confirmed' => 1])->assertRedirect();
        $this->post(route('returns.refund-payment.confirm', [$return, $command]), ['confirmed' => 1])->assertRedirect();
        Queue::assertPushed(ProcessAllegroRefundCommandJob::class, 1);
        $this->assertSame('queued', $command->fresh()->state);
        $this->service()->process($command->id);
        $this->service()->process($command->id);
        $this->assertCount(1, $this->writes);
        $this->assertSame($command->command_id, $this->writes[0]['commandId']);
        $this->assertSame('submitted', $command->fresh()->state);
        $this->assertSame('0.00', app(OrderReturnRefundService::class)->summary($return->fresh())['total']);
        $this->get(route('returns.show', $return))->assertOk()->assertSee('Przyjęte przez Allegro');
        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_delivery_and_product_amounts_use_exact_cents_and_success_is_allocated_once(): void
    {
        $return = $this->returnRecord($this->account());
        $before = $return->order->getAttributes();
        $command = $this->prepare($return, '30.01', '4.99');
        $this->assertSame('35.00', $command->total_value);
        $this->service()->confirm($command, 'owner');
        $this->service()->process($command->id);
        $raw = $this->refund($command->payload, 'SUCCESS');
        $this->refunds = [$raw];
        $this->service()->refresh($command->fresh());
        $this->service()->refresh($command->fresh());
        $this->assertSame('success', $command->fresh()->state);
        $summary = app(OrderReturnRefundService::class)->summary($return->fresh());
        $this->assertSame('30.01', $summary['products']);
        $this->assertSame('4.99', $summary['delivery']);
        $this->assertDatabaseCount('order_return_refund_allocations', 1);
        $this->assertSame($before, $return->order->fresh()->getAttributes());
        $this->assertCount(1, $this->writes);
        $this->assertFalse($return->allegroAccount?->refunds_enabled ?? false);
    }

    public function test_successful_delivery_and_line_money_are_not_available_on_other_return(): void
    {
        $account = $this->account();
        $one = $this->returnRecord($account);
        $two = $this->returnRecord($account, $one->order);
        $command = $this->prepare($one, '60.02', '10.00');
        $this->service()->confirm($command, 'owner');
        $this->responseStatus = 'SUCCESS';
        $this->service()->process($command->id);
        $this->refunds = [$this->refund($command->payload, 'SUCCESS')];
        $fresh = app(AllegroRefundContextService::class)->fresh($two);
        $this->assertSame('0.00', $fresh['delivery_max']);
        $this->assertSame('30.01', array_values($fresh['rows'])[0]['max']);
        $this->assertSame('30.01', $fresh['total_max']);
    }

    public function test_double_drafts_and_other_return_cannot_reserve_the_same_order(): void
    {
        $account = $this->account();
        $one = $this->returnRecord($account);
        $two = $this->returnRecord($account, $one->order);
        $first = $this->prepare($one);
        $other = $this->prepare($two);
        $this->service()->confirm($first, 'owner');
        $this->expectException(ValidationException::class);
        $this->service()->confirm($other, 'owner');
    }

    #[DataProvider('invalidContexts')]
    public function test_unsafe_source_contexts_are_blocked_before_any_post(string $change): void
    {
        $return = $this->returnRecord($this->account());
        match ($change) {
            'cod' => $this->checkout['payment']['type'] = 'CASH_ON_DELIVERY',
            'unpaid' => $this->checkout['payment']['paidAmount']['amount'] = '0.00',
            'other_order' => $this->checkout['id'] = (string) Str::uuid(),
            'currency' => $this->checkout['delivery']['cost']['currency'] = 'EUR',
            'surcharge' => $this->checkout['surcharges'] = [['id' => (string) Str::uuid()]],
            'service' => $this->checkout['lineItems'][0]['selectedAdditionalServices'] = [['id' => 'extra']],
            'mapping' => $return->items()->update(['order_item_id' => null]),
            'rejected' => $return->update(['local_status_id' => ReturnStatusSetting::query()->where('code', 'rejected')->value('id')]),
        };
        try {
            app(AllegroRefundContextService::class)->fresh($return);
            $this->fail('Unsafe context was accepted');
        } catch (ValidationException) {
            $this->assertCount(0, $this->writes);
            $this->assertDatabaseCount('allegro_refund_commands', 0);
        }
    }

    public static function invalidContexts(): array
    {
        return array_map(fn ($v) => [$v], ['cod', 'unpaid', 'other_order', 'currency', 'surcharge', 'service', 'mapping', 'rejected']);
    }

    #[DataProvider('badSelections')]
    public function test_invalid_selection_cannot_create_an_intent(string $amount, string $delivery, int $quantity): void
    {
        $return = $this->returnRecord($this->account());
        $fresh = app(AllegroRefundContextService::class)->fresh($return);
        $data = $this->data($return, $fresh, $amount, $delivery);
        $key = array_key_first($data['items']);
        $data['items'][$key]['quantity'] = $quantity;
        $this->post(route('returns.refund-payment.prepare', $return), $data)->assertSessionHasErrors();
        $this->assertDatabaseCount('allegro_refund_commands', 0);
        $this->assertCount(0, $this->writes);
    }

    public static function badSelections(): array
    {
        return [['60.03', '0.00', 2], ['30.02', '0.00', 1], ['30.01', '10.01', 1], ['0.00', '0.00', 1], ['-1.00', '0.00', 1], ['0.001', '0.00', 1], ['30.01', '0.00', 3]];
    }

    #[DataProvider('changesBeforeSend')]
    public function test_changes_after_confirmation_stop_before_post(string $change): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        match ($change) {
            'off' => $account->update(['refund_write_enabled' => false]),
            'revision' => $account->increment('refund_write_revision'),
            'identity' => $account->update(['environment' => 'sandbox']),
            'return' => $return->increment('lock_version'),
            'source' => $this->checkout['lineItems'][0]['price']['amount'] = '29.00',
            'expired' => $this->travel(6)->minutes(),
            'deleted' => $return->delete(),
        };
        $this->service()->process($command->id);
        $this->assertSame('cancelled', $command->fresh()->state);
        $this->assertCount(0, $this->writes);
    }

    public static function changesBeforeSend(): array
    {
        return array_map(fn ($v) => [$v], ['off', 'revision', 'identity', 'return', 'source', 'expired', 'deleted']);
    }

    public function test_expired_and_wrong_session_confirmations_do_not_queue(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        try {
            $this->service()->confirm($command, 'other-session');
            $this->fail('Wrong session accepted');
        } catch (ValidationException) {
            $this->assertSame('draft', $command->fresh()->state);
        }
        $this->travel(6)->minutes();
        $this->expectException(ValidationException::class);
        $this->service()->confirm($command, 'owner');
    }

    #[DataProvider('ambiguousStatuses')]
    public function test_partial_or_unknown_result_is_not_counted_as_returned(string $status): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->responseStatus = $status;
        $this->service()->process($command->id);
        $this->assertSame('review', $command->fresh()->state);
        $this->assertSame('0.00', app(OrderReturnRefundService::class)->summary($return->fresh())['total']);
        $this->assertDatabaseCount('order_return_refund_allocations', 0);
    }

    public static function ambiguousStatuses(): array
    {
        return [['PARTIAL'], ['FUTURE_STATUS']];
    }

    public function test_timeout_reserves_money_and_does_not_automatically_repost(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->timeout = true;
        $this->service()->process($command->id);
        $this->service()->process($command->id);
        $this->service()->dispatchDue();
        $this->assertSame('uncertain', $command->fresh()->state);
        $this->assertCount(1, $this->writes);
        $this->assertStringNotContainsString('PRIVATE', $command->fresh()->error);
        $this->assertStringNotContainsString('PRIVATE', json_encode(DB::table('integration_api_logs')->get()));
        $this->expectException(ValidationException::class);
        $this->prepare($return);
    }

    public function test_success_can_be_reconciled_after_timeout_without_a_second_post(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->timeout = true;
        $this->service()->process($command->id);
        $raw = $this->refund($command->payload, 'SUCCESS');
        $this->refunds = [$raw];
        $this->service()->bindUncertain($command->fresh(), $raw['id']);
        $this->assertSame('success', $command->fresh()->state);
        $this->assertSame('30.01', app(OrderReturnRefundService::class)->summary($return->fresh())['total']);
        $this->assertCount(1, $this->writes);
    }

    public function test_definitive_422_rejection_is_not_a_success_and_logs_are_sanitized(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->httpError = 422;
        $this->service()->process($command->id);
        $this->assertSame('failed', $command->fresh()->state);
        $this->assertDatabaseCount('order_return_refund_allocations', 0);
        $this->assertStringNotContainsString('PRIVATE', json_encode(DB::table('integration_api_logs')->get()));
    }

    public function test_crashed_sending_is_recovered_as_uncertain_not_requeued_for_post(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $command->update(['state' => 'sending', 'attempts' => 1, 'started_at' => now()->subMinutes(6)]);
        $this->service()->dispatchDue();
        $this->assertSame('uncertain', $command->fresh()->state);
        Queue::assertNothingPushed();
        $this->assertCount(0, $this->writes);
    }

    public function test_confirmed_financial_history_survives_return_and_order_deletion(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->responseStatus = 'SUCCESS';
        $this->service()->process($command->id);
        $return->delete();
        $return->order->forceDelete();
        $this->assertNull($command->fresh()->order_return_id);
        $this->assertNull($command->fresh()->order_id);
        $this->assertSame($return->id, $command->fresh()->return_number);
        $this->assertDatabaseCount('order_return_refund_allocations', 1);
        $this->assertDatabaseCount('allegro_payment_refunds', 1);
    }

    public function test_payload_is_immutable_after_preparation(): void
    {
        $command = $this->prepare($this->returnRecord($this->account()));
        $this->expectException(\LogicException::class);
        $command->update(['total_value' => '0.01']);
    }

    public function test_external_pending_refund_reduces_both_available_pools(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return, '30.01', '2.99');
        $this->refunds = [$this->refund($command->payload, 'IN_PROGRESS')];
        $fresh = app(AllegroRefundContextService::class)->fresh($return);
        $this->assertSame('7.01', $fresh['delivery_max']);
        $this->assertSame('67.03', $fresh['total_max']);
    }

    public function test_later_partial_result_invalidates_previously_counted_allocation(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->responseStatus = 'SUCCESS';
        $this->service()->process($command->id);
        $this->refunds = [$this->refund($command->payload, 'PARTIAL')];
        $this->service()->refresh($command->fresh());
        $this->assertSame('review', $command->fresh()->state);
        $this->assertSame('0.00', app(OrderReturnRefundService::class)->summary($return->fresh())['total']);
        $this->assertDatabaseCount('order_return_refund_allocations', 1);
    }

    public function test_managed_allocation_cannot_be_reassigned_manually(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->responseStatus = 'SUCCESS';
        $this->service()->process($command->id);
        $refund = AllegroPaymentRefund::query()->sole();
        $this->patch(route('returns.refunds.allocate', [$return, $refund]), ['expected_return_version' => $return->fresh()->lock_version,
            'expected_refund_version' => $refund->lock_version, 'products_value' => '0.00', 'delivery_value' => '0.00'])->assertSessionHasErrors('refund_allocation');
        $this->assertSame('30.01', app(OrderReturnRefundService::class)->summary($return->fresh())['total']);
    }

    public function test_401_refresh_reuses_exact_payload_and_command_uuid(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->post401 = 1;
        $this->service()->process($command->id);
        $this->assertCount(2, $this->writes);
        $this->assertSame($this->writes[0], $this->writes[1]);
        $this->assertSame($command->command_id, $this->writes[1]['commandId']);
        $this->assertSame('submitted', $command->fresh()->state);
    }

    public function test_malformed_success_response_is_uncertain_not_a_definitive_rejection(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->responseOverride = function ($request) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/payments/refunds')) {
                $this->writes[] = $request->data();

                return Http::response(['id' => '478e1eb5-72c8-4c59-a055-a4f55bfa0299']);
            }

            return null;
        };
        $this->service()->process($command->id);
        $this->assertSame('uncertain', $command->fresh()->state);
        $this->assertNotNull($command->fresh()->external_refund_id);
        $this->assertDatabaseCount('order_return_refund_allocations', 0);
        $this->responseOverride = null;
        $this->refunds = [$this->refund($command->payload, 'SUCCESS')];
        $this->service()->process($command->id);
        $this->assertSame('success', $command->fresh()->state);
        $this->assertCount(1, $this->writes);
    }

    public function test_accepted_financial_fact_is_retained_if_write_gate_is_disabled_during_post(): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->responseOverride = function ($request) use ($account) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/payments/refunds')) {
                $this->writes[] = $request->data();
                $account->update(['refund_write_enabled' => false, 'refund_write_revision' => 1]);

                return Http::response($this->refund($request->data(), 'SUCCESS'));
            }

            return null;
        };
        $this->service()->process($command->id);
        $this->assertSame('success', $command->fresh()->state);
        $this->assertSame('30.01', app(OrderReturnRefundService::class)->summary($return->fresh())['total']);
        $this->assertFalse($account->fresh()->refund_write_enabled);
    }

    public function test_json_object_key_order_does_not_break_payload_hash_on_mysql(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $payload = array_reverse($command->payload, true);
        $payload['lineItems'][0] = array_reverse($payload['lineItems'][0], true);
        DB::table('allegro_refund_commands')->where('id', $command->id)->update(['payload' => json_encode($payload)]);
        $this->service()->confirm($command->fresh(), 'owner');
        $this->service()->process($command->id);
        $this->assertSame('submitted', $command->fresh()->state);
        $this->assertCount(1, $this->writes);
    }

    public function test_all_refund_pages_are_checked_not_only_first_hundred(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return, '0.01');
        for ($i = 0; $i < 101; $i++) {
            $row = $this->refund($command->payload, 'SUCCESS');
            $row['id'] = (string) Str::uuid();
            $this->refunds[] = $row;
        }
        $fresh = app(AllegroRefundContextService::class)->fresh($return);
        $this->assertSame('99.02', $fresh['total_max']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'offset=100'));
    }

    public function test_partial_or_truncated_refund_page_cannot_be_used_for_payment(): void
    {
        $return = $this->returnRecord($this->account());
        $this->responseOverride = fn ($request) => str_contains($request->url(), '/payments/refunds')
            ? Http::response(['refunds' => [], 'count' => 0, 'totalCount' => 1]) : null;
        $this->expectException(ValidationException::class);
        $this->prepare($return);
    }

    public function test_unquantified_historical_refund_blocks_new_payment(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $row = $this->refund($command->payload, 'SUCCESS');
        $row['lineItems'][0] = ['id' => $row['lineItems'][0]['id'], 'type' => 'QUANTITY', 'quantity' => 1];
        $this->refunds = [$row];
        $this->expectException(ValidationException::class);
        $this->prepare($return);
    }

    public function test_disconnect_disables_write_without_removing_reservations_or_history(): void
    {
        $account = $this->account();
        $command = $this->prepare($this->returnRecord($account));
        $command->update(['state' => 'uncertain']);
        app(AllegroAccountService::class)->disconnect($account);
        $this->assertFalse($account->fresh()->refund_write_enabled);
        $this->assertSame(1, $account->fresh()->refund_write_revision);
        $this->assertSame('uncertain', $command->fresh()->state);
        $this->assertDatabaseCount('allegro_refund_commands', 1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_quote_does_not_queue_or_send_and_final_confirmation_is_required(): void
    {
        $this->withSession(['refund_flow' => 'test'])->withCookie(config('session.cookie'), session()->getId());
        $return = $this->returnRecord($this->account());
        $fresh = app(AllegroRefundContextService::class)->fresh($return);
        $this->post(route('returns.refund-payment.prepare', $return), $this->data($return, $fresh))->assertRedirect();
        $command = AllegroRefundCommand::query()->sole();
        $this->post(route('returns.refund-payment.confirm', [$return, $command]), [])->assertSessionHasErrors('confirmed');
        $this->assertSame('draft', $command->fresh()->state);
        Queue::assertNothingPushed();
        $this->assertCount(0, $this->writes);
    }

    public function test_wrong_external_refund_id_cannot_resolve_an_uncertain_payment(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->timeout = true;
        $this->service()->process($command->id);
        $row = $this->refund($command->payload, 'SUCCESS');
        $row['payment']['id'] = (string) Str::uuid();
        $this->refunds = [$row];
        try {
            $this->service()->bindUncertain($command->fresh(), $row['id']);
            $this->fail('Wrong payment accepted');
        } catch (ValidationException) {
            $this->assertNull($command->fresh()->external_refund_id);
            $this->assertSame('uncertain', $command->fresh()->state);
        }
    }

    public function test_local_ledger_change_between_http_read_and_db_lock_rejects_confirmation(): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $other = $this->returnRecord($account, $return->order);
        $first = $this->prepare($return);
        $second = $this->prepare($other);
        $armed = false;
        $this->responseOverride = function ($request) use (&$armed) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/payments/refunds')) {
                $armed = true;
            }

            return null;
        };
        DB::listen(function ($query) use (&$armed, $first): void {
            if ($armed && str_contains($query->sql, 'from "allegro_accounts"')) {
                $armed = false;
                AllegroRefundCommand::query()->whereKey($first->id)->update(['state' => 'queued']);
            }
        });
        try {
            $this->service()->confirm($second, 'owner');
            $this->fail('Stale ledger was accepted');
        } catch (ValidationException) {
            $this->assertSame('draft', $second->fresh()->state);
            $this->assertCount(0, $this->writes);
        }
    }

    public function test_5xx_does_not_release_money_or_trigger_an_automatic_new_command(): void
    {
        $return = $this->returnRecord($this->account());
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->httpError = 503;
        $this->service()->process($command->id);
        $this->service()->process($command->id);
        $this->assertSame('uncertain', $command->fresh()->state);
        $this->assertCount(1, $this->writes);
        $this->assertDatabaseCount('allegro_refund_commands', 1);
    }

    public function test_known_result_is_polled_even_when_new_writes_and_bulk_import_are_off(): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->service()->process($command->id);
        $account->update(['refund_write_enabled' => false, 'refunds_enabled' => false]);
        $this->refunds = [$this->refund($command->payload, 'SUCCESS')];
        $this->travel(5)->minutes();
        Queue::fake();
        $this->service()->dispatchDue();
        Queue::assertPushed(ProcessAllegroRefundCommandJob::class, fn ($job) => $job->commandId === $command->id);
        $this->service()->process($command->id);
        $this->assertSame('success', $command->fresh()->state);
        $this->assertSame('30.01', app(OrderReturnRefundService::class)->summary($return->fresh())['total']);
        $this->assertCount(1, $this->writes);
    }

    public function test_changed_account_identity_backs_off_result_polling_without_losing_reservation(): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $command = $this->prepare($return);
        $this->service()->confirm($command, 'owner');
        $this->service()->process($command->id);
        $account->update(['environment' => 'sandbox']);
        $this->service()->refresh($command->fresh());
        $this->assertSame('submitted', $command->fresh()->state);
        $this->assertNotNull($command->fresh()->error);
        $this->assertTrue($command->fresh()->next_check_at->equalTo(now()->addMinutes(5)));
        $this->assertCount(1, $this->writes);
    }

    private function account(bool $write = true): AllegroAccount
    {
        return AllegroAccount::query()->create(['account_key' => 'default', 'name' => 'multi-click', 'environment' => 'production',
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(5), 'granted_scopes' => [...AllegroAccount::REQUIRED_SCOPES, AllegroAccount::PAYMENTS_READ_SCOPE, ...($write ? [AllegroAccount::PAYMENTS_WRITE_SCOPE] : [])],
            'allegro_user_id' => 'seller-1', 'is_active' => true, 'last_test_status' => 'success',
            'refund_write_enabled' => $write, 'refund_write_scope_requested' => $write, 'refund_write_identity' => 'production:seller-1']);
    }

    private function returnRecord(AllegroAccount $account, ?Order $order = null): OrderReturn
    {
        if (! $order) {
            $order = Order::query()->create(['source' => 'allegro', 'status' => 'new', 'currency' => 'PLN', 'total_gross' => '100.03', 'paid_amount' => '100.03']);
            AllegroOrderLink::query()->create(['allegro_account_id' => $account->id, 'order_id' => $order->id, 'environment' => 'production', 'seller_user_id' => 'seller-1',
                'account_name' => 'multi-click', 'external_order_id' => AllegroOrderFixture::ORDER_ID, 'external_status' => 'READY_FOR_PROCESSING',
                'source_hash' => hash('sha256', 'order'), 'imported_at' => now(), 'import_state' => 'complete']);
            $order->items()->create(['external_id' => $this->checkout['lineItems'][0]['id'], 'offer_id' => '12345678901', 'product_name' => 'Produkt', 'quantity' => 3, 'unit_price_gross' => '30.01', 'total_price_gross' => '90.03', 'currency' => 'PLN']);
        }
        $return = OrderReturn::query()->create(['source' => 'allegro', 'creation_method' => 'manual', 'order_id' => $order->id,
            'local_status_id' => ReturnStatusSetting::initial()->id, 'currency' => 'PLN', 'total_value' => '60.02', 'buyer_name' => 'Jan Testowy', 'submitted_at' => now()]);
        $return->items()->create(['order_item_id' => $order->items()->sole()->id, 'position' => 1, 'offer_id' => '12345678901',
            'name' => 'Produkt', 'quantity' => 2, 'unit_price' => '30.01', 'total_value' => '60.02', 'currency' => 'PLN', 'reason' => 'MISTAKE']);

        return $return->fresh();
    }

    private function data(OrderReturn $return, array $fresh, string $amount = '60.02', string $delivery = '0.00'): array
    {
        return ['context_hash' => $fresh['hash'], 'items' => [$return->items()->sole()->id => ['selected' => 1, 'quantity' => 2, 'amount' => $amount]],
            'include_delivery' => $delivery !== '0.00' ? 1 : 0, 'delivery_amount' => $delivery, 'reason' => 'REFUND', 'seller_comment' => ''];
    }

    private function prepare(OrderReturn $return, string $amount = '30.01', string $delivery = '0.00'): AllegroRefundCommand
    {
        $fresh = app(AllegroRefundContextService::class)->fresh($return);

        return $this->service()->prepare($return, $this->data($return, $fresh, $amount, $delivery), 'owner');
    }

    private function refund(array $payload, string $status): array
    {
        $total = BigDecimal::of(data_get($payload, 'delivery.value.amount', '0.00'));
        foreach ($payload['lineItems'] ?? [] as $line) {
            $total = $total->plus($line['value']['amount']);
        }
        unset($payload['commandId']);

        return $payload + ['id' => '478e1eb5-72c8-4c59-a055-a4f55bfa0299', 'status' => $status,
            'createdAt' => '2026-10-22T10:00:00Z', 'totalValue' => ['amount' => (string) $total->toScale(2), 'currency' => 'PLN']];
    }

    private function settings(AllegroAccount $account): array
    {
        return ['identity' => $account->orderImportIdentity(), 'configuration_revision' => $account->refund_write_revision,
            'refund_write_scope_requested' => 1, 'refund_write_enabled' => 1];
    }

    private function service(): AllegroRefundCommandService
    {
        return app(AllegroRefundCommandService::class);
    }
}
