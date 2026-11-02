<?php

namespace Tests\Feature;

use App\Models\OrderStatusSetting;
use App\Services\OrderStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\Integrations\VonHalsky\Jobs\ProcessVonHalskyOrderCommandJob;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderCommand;
use Modules\Integrations\VonHalsky\Models\VonHalskyStatusMapping;
use Modules\Integrations\VonHalsky\Services\VonHalskyAccountService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderCommandService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderImportDispatcher;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderImportService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderPreviewService;
use Modules\Integrations\VonHalsky\Services\VonHalskyStatusMappingService;
use Modules\Invoices\Services\InvoiceIssuingService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\VonHalsky\CatalogFixtures;
use Tests\TestCase;

class VonHalskyOrderCommandTest extends TestCase
{
    use CatalogFixtures, CreatesInvoiceStage2CDocuments, RefreshDatabase;

    private const COMMAND = 'cdb535f2-55f2-4e42-8093-52283031fda8';

    private int $writeCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-30 14:00:00', config('app.timezone')));
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        OrderStatusSetting::syncDefaults();
        $this->fakeApi();
    }

    public function test_defaults_get_pages_and_mapping_save_never_send_or_backfill(): void
    {
        $this->get(route('integrations.von-halsky.edit', ['tab' => 'statuses']))->assertOk()->assertSee('Powiązanie statusów');
        $this->assertDatabaseCount('von_halsky_accounts', 0);
        [$account, $order] = $this->imported();
        $this->assertFalse($account->status_sync_enabled);
        $this->assertDatabaseCount('von_halsky_status_mappings', 0);
        $order->update(['status' => 'shipped']);
        $custom = OrderStatusSetting::query()->create(['status' => 'test_custom', 'short_name' => 'Własny status', 'full_name' => 'Własny', 'sort_order' => 50, 'color' => '#123456']);
        $page = $this->get(route('integrations.von-halsky.edit', ['tab' => 'statuses']))->assertOk()
            ->assertSee('Zaakceptowano')->assertSee('Anulowane')->assertSee('Własny status')->assertDontSee('Zablokowane');
        $this->assertStringContainsString('outbound['.$custom->id.']', $page->getContent());
        $this->post(route('integrations.von-halsky.status-mappings'), $this->mappingData($account, 'accept', 'new'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('accept', VonHalskyStatusMapping::query()->sole()->action->value);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Nie przekazano');
        $this->getJson(route('orders.state', $order))->assertOk();
        $this->assertDatabaseCount('von_halsky_order_commands', 0);
        Queue::assertNotPushed(ProcessVonHalskyOrderCommandJob::class);
        $this->assertSame(0, $this->posts());
    }

    #[DataProvider('invalidMappings')]
    public function test_invalid_mapping_is_rejected_without_account_or_history_changes(array $override): void
    {
        $account = $this->account();
        $before = $account->getAttributes();
        $this->postJson(route('integrations.von-halsky.status-mappings'), array_replace($this->mappingData($account), $override))->assertUnprocessable();
        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertDatabaseCount('von_halsky_status_mappings', 0);
        $this->assertDatabaseCount('von_halsky_order_commands', 0);
        Http::assertNothingSent();
    }

    public static function invalidMappings(): array
    {
        return [[['expected_lock_version' => null]], [['expected_lock_version' => 20]], [['status_sync_enabled' => 2]],
            [['outbound' => 'accept']], [['outbound' => ['invalid' => 'accept']]], [['outbound' => [999999 => 'cancel']]],
            [['outbound' => [1 => 'refund']]], [['outbound' => [1 => ['accept']]]]];
    }

    public function test_disabled_or_unmapped_and_other_source_do_not_queue_commands(): void
    {
        [$account, $order] = $this->imported();
        $order->update(['status' => 'shipped']);
        $this->assertFalse($this->service()->enqueue($order->id, 'shipped', (string) Str::uuid()));
        $this->enable($account, 'accept', 'pending');
        $this->assertFalse($this->service()->enqueue($order->id, 'shipped', (string) Str::uuid()));
        $this->enable($account->fresh());
        $order->update(['source' => 'manual']);
        $this->assertFalse($this->service()->enqueue($order->id, 'shipped', (string) Str::uuid()));
        $this->assertDatabaseCount('von_halsky_order_commands', 0);
        $this->assertSame(0, $this->posts());
    }

    #[DataProvider('changeSources')]
    public function test_real_status_change_queues_once_for_manual_bulk_and_automation(string $source): void
    {
        [$account, $order] = $this->imported();
        $this->enable($account);
        app(OrderStatusService::class)->change($order, 'shipped', $source);
        $this->assertDatabaseCount('von_halsky_order_commands', 1);
        Queue::assertPushed(ProcessVonHalskyOrderCommandJob::class, 1);
        $command = VonHalskyOrderCommand::query()->sole();
        $this->assertSame('queued', $command->state);
        $this->assertSame('shipped', $command->oms_status);
        $this->runCommand($command);
        $this->assertSame('pending', $command->fresh()->state);
        $this->assertSame(1, $this->posts());
    }

    public static function changeSources(): array
    {
        return [['manual'], ['bulk'], ['automation']];
    }

    public function test_status_accepted_is_not_confirmation_and_async_success_preserves_finances(): void
    {
        [$account, $order] = $this->imported();
        app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext('2026-10-30 14:00:00'));
        $command = $this->queued($account, $order);
        $tables = ['orders', 'order_items', 'invoices', 'invoice_items', 'invoice_series', 'invoice_number_counters', 'invoice_number_counter_adjustments', 'order_document_slots', 'shipments', 'automation_runs'];
        $before = $this->snapshot($tables);
        $signature = $this->getJson(route('orders.state', $order))->json('von_halsky_status_signature');
        $this->runCommand($command);
        $this->assertSame('pending', $command->fresh()->state);
        $this->assertSame(self::COMMAND, $command->fresh()->command_id);
        $this->assertSame(0, $order->events()->where('event_type', 'von_halsky_command_succeeded')->count());
        $this->runCommand($command->fresh());
        $this->assertSame('succeeded', $command->fresh()->state);
        $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame(1, $order->events()->where('event_type', 'von_halsky_command_succeeded')->count());
        $this->getJson(route('orders.state', $order).'?von_halsky_status_signature='.$signature)->assertOk()->assertSee('Potwierdzono');
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Potwierdzono');
        $this->assertSame(1, $this->posts());
        Mail::assertNothingSent();
    }

    public function test_success_duplicate_event_job_and_later_status_entry_never_resend(): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        $this->assertFalse($this->service()->enqueue($order->id, 'shipped', $command->event_id));
        $this->runCommand($command);
        $this->runCommand($command->fresh());
        $this->runCommand($command->fresh());
        app(OrderStatusService::class)->change($order->fresh(), 'pending');
        app(OrderStatusService::class)->change($order->fresh(), 'shipped');
        $this->assertDatabaseCount('von_halsky_order_commands', 1);
        $this->assertSame(1, $this->posts());
    }

    #[DataProvider('definiteErrors')]
    public function test_definite_rejection_can_be_retried_with_lock_version_and_new_history(int $httpStatus): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        $this->fakeApi(postStatus: $httpStatus);
        $this->runCommand($command);
        $old = $command->fresh();
        $this->assertSame('failed', $old->state);
        $this->assertNull($old->operation_key);
        if ($httpStatus === 429) {
            $this->travel(61)->seconds();
        }
        $this->fakeApi();
        $url = route('orders.von-halsky-command.retry', [$order, $old->id]);
        $this->postJson($url, ['expected_lock_version' => 999])->assertUnprocessable();
        $this->post($url, ['expected_lock_version' => $old->lock_version])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson($url, ['expected_lock_version' => $old->lock_version])->assertUnprocessable();
        $this->assertDatabaseCount('von_halsky_order_commands', 2);
        $next = VonHalskyOrderCommand::query()->latest('id')->first();
        $this->assertSame($old->id, $next->retry_of_id);
        $this->runCommand($next);
        $this->assertSame('pending', $next->fresh()->state);
        $this->assertSame(1, $this->posts());
    }

    public static function definiteErrors(): array
    {
        return [[400], [401], [403], [404], [422], [429]];
    }

    #[DataProvider('ambiguousErrors')]
    public function test_ambiguous_post_never_retries_or_claims_success(string $mode): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        $this->fakeApi(postStatus: $mode === 'server' ? 500 : 202, receipt: $mode === 'invalid' ? [] : null, timeout: $mode === 'timeout');
        $this->runCommand($command);
        $this->assertSame('uncertain', $command->fresh()->state);
        $this->assertNotNull($command->fresh()->operation_key);
        $this->service()->dispatchDue();
        $this->runCommand($command->fresh());
        $this->postJson(route('orders.von-halsky-command.retry', [$order, $command->id]), ['expected_lock_version' => $command->fresh()->lock_version])->assertUnprocessable();
        $this->assertSame(1, $this->posts());
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Wymaga sprawdzenia')->assertDontSee('SECRET_HTTP');
    }

    public static function ambiguousErrors(): array
    {
        return [['server'], ['timeout'], ['invalid']];
    }

    public function test_known_id_with_unknown_status_is_reconciled_only_by_get(): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        $this->fakeApi(receipt: ['commandId' => self::COMMAND, 'status' => 'NEW_UNKNOWN_STATUS']);
        $this->runCommand($command);
        $this->assertSame('uncertain', $command->fresh()->state);
        $this->runCommand($command->fresh());
        $this->assertSame('succeeded', $command->fresh()->state);
        $this->assertSame(1, $this->posts());
    }

    public function test_remote_command_id_cannot_confirm_a_different_order(): void
    {
        [$account, $order, $link] = $this->imported();
        $first = $this->queued($account, $order);
        $this->runCommand($first);
        $this->runCommand($first->fresh());
        $copy = $order->replicate()->forceFill(['external_id' => 'SECOND_ORDER']);
        $copy->save();
        $link->replicate()->forceFill(['order_id' => $copy->id, 'external_order_id' => 'SECOND_ORDER'])->save();
        $this->service()->enqueue($copy->id, 'shipped', (string) Str::uuid());
        $second = VonHalskyOrderCommand::query()->latest('id')->first();
        $source = $this->sourceOrder();
        $source['id'] = 'SECOND_ORDER';
        $this->fakeApi(source: $source);
        $this->runCommand($second);
        $this->assertSame('uncertain', $second->fresh()->state);
        $this->assertNull($second->fresh()->command_id);
        $this->assertSame('succeeded', $first->fresh()->state);
        $this->assertSame(0, $copy->events()->where('event_type', 'von_halsky_command_succeeded')->count());
        $this->assertSame(1, $this->posts());
    }

    public function test_command_failure_and_wrong_poll_identity_are_not_success(): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        $this->runCommand($command);
        $this->fakeApi(poll: ['commandId' => (string) Str::uuid(), 'status' => 'SUCCESS']);
        $this->runCommand($command->fresh());
        $this->assertSame('pending', $command->fresh()->state);
        $this->assertSame('poll_failed', $command->fresh()->last_error_code);
        $this->fakeApi(poll: ['commandId' => self::COMMAND, 'status' => 'FAILURE']);
        $this->runCommand($command->fresh());
        $this->assertSame('failed', $command->fresh()->state);
        $this->assertNull($command->fresh()->operation_key);
        $this->assertSame(0, $this->posts());
    }

    public function test_disabling_mapping_does_not_lose_pending_result_and_import_can_remain_off(): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        $this->runCommand($command);
        $this->assertFalse($account->fresh()->order_import_enabled);
        $this->post(route('integrations.von-halsky.status-mappings'), array_replace($this->mappingData($account->fresh()), ['status_sync_enabled' => 0]))->assertRedirect();
        app(OrderStatusService::class)->change($order->fresh(), 'pending');
        $this->travel(61)->seconds();
        $this->assertSame(1, app(VonHalskyOrderImportDispatcher::class)->dispatchDue());
        $this->runCommand($command->fresh());
        $this->assertSame('succeeded', $command->fresh()->state);
        $this->assertFalse($account->fresh()->status_sync_enabled);
        $this->assertSame(1, $this->posts());
    }

    #[DataProvider('staleContexts')]
    public function test_stale_or_deleted_context_never_posts(string $mode): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        $account->refresh();
        match ($mode) {
            'status' => $order->update(['status' => 'pending']),
            'source' => $order->update(['source' => 'manual']),
            'external' => $order->update(['external_id' => 'OTHER']),
            'trash' => $order->delete(),
            'delete' => $order->forceDelete(),
            'revision' => $account->increment('status_sync_revision'),
            'connection' => $account->increment('connection_revision'),
            'disabled' => $account->update(['status_sync_enabled' => false]),
            'organization' => $account->update(['organization_id' => (string) Str::uuid()]),
            'environment' => $account->update(['environment' => 'stage']),
        };
        $this->runCommand($command);
        $this->assertSame('skipped', $command->fresh()->state);
        $this->assertSame(0, $this->posts());
    }

    public static function staleContexts(): array
    {
        return array_map(fn ($mode) => [$mode], ['status', 'source', 'external', 'trash', 'delete', 'revision', 'connection', 'disabled', 'organization', 'environment']);
    }

    public function test_configuration_changed_during_get_prevents_post(): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(function () use ($account) {
            $account->increment('status_sync_revision');

            return Http::response($this->sourceOrder());
        });
        $this->runCommand($command);
        $this->assertSame('skipped', $command->fresh()->state);
        $this->assertSame(0, $this->posts());
    }

    public function test_configuration_changed_during_write_authorization_prevents_post(): void
    {
        [$account, $order] = $this->imported();
        $account->update(['access_token_scopes' => null]);
        $command = $this->queued($account, $order);
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(function ($request) use ($account) {
            if (str_ends_with($request->url(), '/oauth2/token')) {
                $account->increment('status_sync_revision');

                return Http::response(['access_token' => 'NEW_TEST_TOKEN', 'token_type' => 'Bearer', 'expires_in' => 3600,
                    'scope' => 'openid api:orders:read api:orders:write']);
            }

            return Http::response($this->sourceOrder());
        });
        $this->runCommand($command);
        $this->assertSame('skipped', $command->fresh()->state);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/accept'));
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_remote_source_is_blocked_without_changes(array $override): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        $before = $order->fresh()->getAttributes();
        $this->fakeApi(source: array_replace_recursive($this->sourceOrder(), $override));
        $this->runCommand($command);
        $this->assertSame('blocked', $command->fresh()->state);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame(0, $this->posts());
    }

    public static function invalidSources(): array
    {
        return [[['id' => 'OTHER']], [['organizationId' => 'OTHER']], [['status' => 'CANCELED']],
            [['status' => 'REFUSED']], [['status' => 'UNKNOWN']], [['paymentDetails' => ['payments' => null]]],
            [['paymentDetails' => ['payments' => 'INVALID']]]];
    }

    public function test_duplicate_worker_while_post_is_inflight_does_not_send_another_command(): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(function ($request) use ($command) {
            if ($request->method() === 'POST') {
                $this->writeCalls++;
                $this->service()->process($command->id);
                $this->assertSame('', $request->body());

                return Http::response(['commandId' => self::COMMAND, 'status' => 'PENDING'], 202);
            }

            return Http::response($this->sourceOrder());
        });
        $this->runCommand($command);
        $this->assertSame('pending', $command->fresh()->state);
        $this->assertSame(1, $this->posts());
    }

    public function test_queued_job_recovery_uses_existing_scheduler_and_does_not_duplicate_post(): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        $this->travel(181)->seconds();
        $this->assertSame(1, app(VonHalskyOrderImportDispatcher::class)->dispatchDue());
        Queue::assertPushed(ProcessVonHalskyOrderCommandJob::class, 2);
        $this->runCommand($command);
        $this->runCommand($command->fresh());
        $this->assertSame(1, $this->posts());
        $this->assertSame('succeeded', $command->fresh()->state);
    }

    public function test_rollback_discards_the_command_and_its_pending_job(): void
    {
        [$account, $order] = $this->imported();
        $this->enable($account);
        $order->update(['status' => 'shipped']);
        DB::beginTransaction();
        $this->assertTrue($this->service()->enqueue($order->id, 'shipped', (string) Str::uuid()));
        DB::rollBack();
        $this->assertDatabaseCount('von_halsky_order_commands', 0);
        Queue::assertNotPushed(ProcessVonHalskyOrderCommandJob::class);
        $this->assertSame(0, $this->posts());
    }

    public function test_bulk_route_uses_same_status_listener(): void
    {
        [$account, $order] = $this->imported();
        $this->enable($account);
        $this->post(route('orders.bulk-status'), ['order_ids' => [$order->id], 'status' => 'shipped'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('von_halsky_order_commands', 1);
        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame(0, $this->posts());
    }

    public function test_scope_denial_preserves_read_connection_and_does_not_retry_post(): void
    {
        [$account, $order] = $this->imported();
        $account->update(['access_token_scopes' => null]);
        $command = $this->queued($account, $order);
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(fn ($request) => str_ends_with($request->url(), '/oauth2/token') ? Http::response([], 403) : Http::response($this->sourceOrder()));
        $this->runCommand($command);
        $this->assertSame('failed', $command->fresh()->state);
        $this->assertTrue($account->fresh()->is_active);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/accept'));
    }

    public function test_changed_connection_disables_writes_without_removing_history_or_mappings(): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        $this->runCommand($command);
        $account->refresh();
        app(VonHalskyAccountService::class)->update(['expected_lock_version' => $account->lock_version, 'name' => 'Nowa nazwa',
            'environment' => $account->environment->value, 'organization_id' => $account->organization_id,
            'client_id' => $account->client_id, 'client_secret' => 'CHANGED_TEST_SECRET']);
        $this->assertFalse($account->fresh()->status_sync_enabled);
        $this->assertNull($account->fresh()->access_token_scopes);
        $this->assertSame('pending', $command->fresh()->state);
        $this->assertDatabaseCount('von_halsky_status_mappings', 1);
    }

    public function test_paid_cancellation_is_visible_but_does_not_refuse_or_refund(): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order, 'cancel', 'cancelled');
        $before = $order->fresh()->getAttributes();
        $this->runCommand($command);
        $this->assertSame('blocked', $command->fresh()->state);
        $this->assertSame('paid_cancellation_blocked', $command->fresh()->last_error_code);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame(0, $this->posts());
        $this->get(route('orders.show', $order))->assertOk()->assertSee('OMS nie zlecił zwrotu pieniędzy');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/refund'));
    }

    public function test_refusal_is_limited_to_a_confirmed_unpaid_created_source(): void
    {
        [$account, $order, $link] = $this->imported();
        // A future incomplete import may provide this state; current paid imports do not.
        $link->update(['channel_paid_amount' => '0.00']);
        $order->update(['paid_amount' => '0.00', 'payment_status' => 'unpaid']);
        $command = $this->queued($account, $order, 'cancel', 'cancelled');
        $source = $this->sourceOrder();
        $source['status'] = 'CREATED';
        $source['paymentDetails']['payments'] = [];
        $this->fakeApi(source: $source);
        $this->runCommand($command);
        $this->assertSame('pending', $command->fresh()->state);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/refuse'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/refund'));
    }

    #[DataProvider('paymentEvidence')]
    public function test_any_payment_evidence_blocks_refusal_even_if_other_fields_are_empty(string $evidence): void
    {
        [$account, $order, $link] = $this->imported();
        $link->update(['channel_paid_amount' => $evidence === 'channel' ? '195.00' : '0.00']);
        $order->update(['paid_amount' => $evidence === 'local_amount' ? '10.00' : '0.00',
            'payment_status' => $evidence === 'local_status' ? 'paid' : 'unpaid']);
        $command = $this->queued($account, $order, 'cancel', 'cancelled');
        $source = $this->sourceOrder();
        $source['status'] = $evidence === 'accepted' ? 'ACCEPTED' : 'CREATED';
        if ($evidence !== 'api') {
            $source['paymentDetails']['payments'] = [];
        }
        $this->fakeApi(source: $source);
        $this->runCommand($command);
        $this->assertSame('blocked', $command->fresh()->state);
        $this->assertSame(0, $this->posts());
    }

    public static function paymentEvidence(): array
    {
        return [['api'], ['channel'], ['local_amount'], ['local_status'], ['accepted']];
    }

    public function test_write_scope_is_requested_only_for_write_and_old_read_token_is_not_assumed_to_allow_it(): void
    {
        [$account, $order] = $this->imported();
        $account->update(['access_token_scopes' => null]);
        $command = $this->queued($account, $order);
        $this->runCommand($command);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/oauth2/token') && $r['scope'] === 'openid api:orders:read api:orders:write');
        $this->assertContains('api:orders:write', $account->fresh()->access_token_scopes);
        $this->assertSame('pending', $command->fresh()->state);
    }

    public function test_missing_write_grant_does_not_post_or_disable_read_connection(): void
    {
        [$account, $order] = $this->imported();
        $account->update(['access_token_scopes' => null]);
        $command = $this->queued($account, $order);
        $this->fakeApi(granted: 'openid api:orders:read');
        $this->runCommand($command);
        $this->assertSame('failed', $command->fresh()->state);
        $this->assertTrue($account->fresh()->is_active);
        $this->assertSame(0, $this->posts());
    }

    public function test_lost_worker_after_sending_is_uncertain_not_a_new_post(): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        $command->update(['state' => 'sending', 'sending_at' => now()->subMinutes(4)]);
        $this->runCommand($command);
        $this->assertSame('uncertain', $command->fresh()->state);
        $this->assertNotNull($command->fresh()->operation_key);
        $this->assertSame(0, $this->posts());
    }

    public function test_post_result_survives_history_write_failure_without_a_second_post(): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        DB::statement("CREATE TRIGGER vh_cmd_fail BEFORE INSERT ON order_events WHEN NEW.event_type='von_halsky_command_succeeded' BEGIN SELECT RAISE(ABORT,'FAIL'); END");
        $this->fakeApi(receipt: ['commandId' => self::COMMAND, 'status' => 'SUCCESS']);
        $this->runCommand($command);
        $this->assertSame('uncertain', $command->fresh()->state);
        $this->assertSame(self::COMMAND, $command->fresh()->command_id);
        DB::statement('DROP TRIGGER vh_cmd_fail');
        $this->runCommand($command->fresh());
        $this->assertSame('succeeded', $command->fresh()->state);
        $this->assertSame(1, $this->posts());
    }

    public function test_history_survives_deletion_and_used_migration_cannot_be_rolled_back(): void
    {
        [$account, $order] = $this->imported();
        $command = $this->queued($account, $order);
        $this->runCommand($command);
        $order->forceDelete();
        $account->delete();
        $this->assertNull($command->fresh()->order_id);
        $this->assertNull($command->fresh()->von_halsky_account_id);
        $this->assertSame(self::COMMAND, $command->fresh()->command_id);
        $migration = require database_path('migrations/2026_10_30_020000_add_von_halsky_outbound_order_commands.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_csrf_portal_host_and_partial_migration_are_protected(): void
    {
        [$account, $order] = $this->imported();
        config(['app.url' => 'https://oms.example.test']);
        PortalSetting::query()->updateOrCreate(['singleton_key' => 'default'], ['customer_domain' => 'klient.example.test']);
        $this->get('https://klient.example.test/integrations/von-halsky?tab=statuses')->assertNotFound();
        $this->post('https://klient.example.test/integrations/von-halsky/status-mappings', $this->mappingData($account))->assertNotFound();
        DB::table('migrations')->where('migration', '2026_10_30_020000_add_von_halsky_outbound_order_commands')->delete();
        $this->assertFalse($this->service()->enqueue($order->id, 'new', (string) Str::uuid()));
        $this->assertSame(0, $this->service()->dispatchDue());
        $this->app->instance('env', 'local');
        $this->post('https://oms.example.test/integrations/von-halsky/status-mappings', $this->mappingData($account))->assertStatus(419);
        $this->assertSame(0, $this->posts());
    }

    private function imported(): array
    {
        $account = $this->account(['default_vat_rate' => '23.00', 'access_token_scopes' => ['openid', 'api:orders:read', 'api:orders:write']]);
        $preview = app(VonHalskyOrderPreviewService::class)->preview($account, 'PG76P11', [1])['import'];
        $order = app(VonHalskyOrderImportService::class)->import($account, 'PG76P11', $preview['source_hash'], [1], true);

        return [$account->fresh(), $order, $order->vonHalskyOrderLink()->sole()];
    }

    private function mappingData($account, string $action = 'accept', string $status = 'shipped'): array
    {
        return ['expected_lock_version' => $account->lock_version, 'status_sync_enabled' => 1,
            'outbound' => [OrderStatusSetting::query()->where('status', $status)->value('id') => $action]];
    }

    private function enable($account, string $action = 'accept', string $status = 'shipped'): void
    {
        app(VonHalskyStatusMappingService::class)->update($this->mappingData($account, $action, $status));
    }

    private function queued($account, $order, string $action = 'accept', string $status = 'shipped'): VonHalskyOrderCommand
    {
        $this->enable($account, $action, $status);
        $order->update(['status' => $status]);
        $this->assertTrue($this->service()->enqueue($order->id, $status, (string) Str::uuid()));

        return VonHalskyOrderCommand::query()->latest('id')->first();
    }

    private function runCommand(VonHalskyOrderCommand $command): void
    {
        $job = new ProcessVonHalskyOrderCommandJob($command->id);
        try {
            $job->handle($this->service());
        } finally {
            app(UniqueLock::class)->release($job);
        }
    }

    private function service(): VonHalskyOrderCommandService
    {
        return app(VonHalskyOrderCommandService::class);
    }

    private function fakeApi(int $postStatus = 202, ?array $receipt = null, ?array $poll = null, ?array $source = null,
        bool $timeout = false, string $granted = 'openid api:orders:read api:orders:write'): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $this->writeCalls = 0;
        Http::fake(function ($request) use ($postStatus, $receipt, $poll, $source, $timeout, $granted) {
            if (str_ends_with($request->url(), '/oauth2/token')) {
                return Http::response(['access_token' => 'NEW_TEST_TOKEN', 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => $granted]);
            }
            if ($request->method() === 'POST') {
                $this->writeCalls++;
                if ($timeout) {
                    throw new ConnectionException('SECRET_HTTP');
                }

                return Http::response($receipt ?? ['commandId' => self::COMMAND, 'status' => 'PENDING'], $postStatus);
            }
            if (str_contains($request->url(), '/orders/commands/')) {
                return Http::response($poll ?? ['commandId' => self::COMMAND, 'status' => 'SUCCESS']);
            }

            return Http::response($source ?? $this->sourceOrder());
        });
    }

    private function posts(): int
    {
        return $this->writeCalls;
    }

    private function snapshot(array $tables): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    }
}
