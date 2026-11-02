<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Events\OrderDataCompleted;
use App\Models\Order;
use App\Models\OrderStatusSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Automation\Jobs\EvaluateAutomationEventJob;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationEngine;
use Modules\Automation\Services\AutomationOrderSources;
use Modules\Automation\Services\AutomationRuleService;
use Modules\Automation\Services\AutomationRunner;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyApiException;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderImportAttempt;
use Modules\Integrations\VonHalsky\Services\VonHalskyClient;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderImportService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderNormalizer;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderPreviewService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderProcessingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VonHalsky\CatalogFixtures;
use Tests\TestCase;

class VonHalskyOrderAutomationTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->sourceOrder())]);
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        OrderStatusSetting::syncDefaults();
    }

    public function test_editor_and_validation_support_halsky_alongside_other_sources(): void
    {
        $account = $this->account();
        $allegro = AllegroAccount::query()->create(['account_key' => 'test', 'name' => 'Allegro test']);
        $presta = PrestashopAccount::query()->create(['account_key' => 'test', 'name' => 'Sklep test', 'base_url' => 'https://shop.example.test']);
        $data = $this->ruleData(selected: ['von_halsky:'.$account->id, 'allegro:'.$allegro->id, 'prestashop:'.$presta->id]);
        $this->postJson(route('orders.automatic-actions.store'), $data)->assertCreated();
        $this->assertSame($data['conditions'], AutomationRule::query()->sole()->conditions);
        $this->get(route('orders.automatic-actions.index'))->assertOk()
            ->assertSee('multi-click (InPost Von Halsky)')->assertSee('Dowolne konto InPost Von Halsky')
            ->assertSee('Allegro test (Allegro)')->assertSee('Sklep test (PrestaShop)');

        $data['conditions'] = [['field' => 'source', 'operator' => 'equals', 'value' => 'von_halsky']];
        $this->postJson(route('orders.automatic-actions.store'), $data)->assertCreated();
        $this->assertSame('InPost Von Halsky', app(AutomationCatalog::class)->conditionDefinitions()['source']['options']['von_halsky']);
        Http::assertNothingSent();
    }

    #[DataProvider('matchingSources')]
    public function test_matching_active_rules_run_without_an_integration_switch(string $trigger, array $selected): void
    {
        $account = $this->account();
        $selected = array_map(fn ($source) => str_replace(':account', ':'.$account->id, $source), $selected);
        $rule = app(AutomationRuleService::class)->create($this->ruleData($trigger, $selected));
        $order = $this->importOrder($account);
        $job = Queue::pushed(EvaluateAutomationEventJob::class)->sole();
        $this->assertSame($trigger, $job->eventName);
        $this->assertSame(['channel' => 'von_halsky', 'account_id' => $account->id], $job->eventPayload['order_source']);
        $this->assertFalse($order->vonHalskyOrderLink->automations_suppressed);
        $this->assertSame(1, $order->items()->count());
        $job->handle(app(AutomationEngine::class));
        $run = $order->automationRuns()->sole();
        $this->assertSame($rule->id, $run->automation_rule_id);
        app(AutomationRunner::class)->execute($run, 0);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame('Imported '.$order->id, $order->fresh()->notes);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('shipments', 0);
        Mail::assertNothingSent();
        Http::assertSentCount(2);
    }

    public static function matchingSources(): array
    {
        $cases = [];
        foreach (['order.created', 'order.data_completed'] as $trigger) {
            foreach (['channel' => ['von_halsky'], 'account' => ['von_halsky:account'], 'all' => ['*'],
                'no condition' => [], 'multiple sources' => ['prestashop', 'von_halsky:account']] as $name => $selected) {
                $cases[$trigger.' '.$name] = [$trigger, $selected];
            }
        }

        return $cases;
    }

    public function test_nonmatching_inactive_and_extra_conditions_do_not_execute(): void
    {
        $account = $this->account();
        $other = $this->account(['account_key' => 'other', 'name' => 'Other',
            'organization_id' => '497f6eca-6276-4993-bfeb-53cbbbba6f08']);
        $service = app(AutomationRuleService::class);
        $service->create($this->ruleData(selected: ['von_halsky:'.$other->id]));
        $service->create($this->ruleData(selected: ['prestashop', 'allegro']));
        $service->create($this->ruleData(active: false));
        $data = $this->ruleData();
        $data['conditions'][] = ['field' => 'order_status', 'operator' => 'equals', 'value' => 'shipped'];
        $service->create($data);
        $order = $this->importOrder($account);
        Queue::pushed(EvaluateAutomationEventJob::class)->sole()->handle(app(AutomationEngine::class));
        $this->assertDatabaseCount('automation_runs', 0);
        $this->assertNull($order->fresh()->notes);
        Mail::assertNothingSent();
    }

    public function test_import_without_active_rules_does_not_queue_actions_or_claim_they_ran(): void
    {
        $account = $this->account();
        app(AutomationRuleService::class)->create($this->ruleData(active: false));
        $order = $this->importOrder($account);
        Queue::assertNothingPushed();
        $event = $order->events()->sole();
        $this->assertFalse($event->payload['automations_suppressed']);
        $this->assertStringNotContainsString('automatyczne akcje', $event->description);
        $this->assertDatabaseCount('automation_runs', 0);
    }

    public function test_events_wait_for_the_import_transaction_to_commit(): void
    {
        $account = $this->account();
        app(AutomationRuleService::class)->create($this->ruleData());
        DB::beginTransaction();
        $order = $this->importOrder($account);
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(EvaluateAutomationEventJob::class, 1);
        Queue::assertPushed(EvaluateAutomationEventJob::class, fn ($job) => $job->eventPayload['order_id'] === $order->id);
    }

    public function test_rollback_discards_import_and_its_pending_events(): void
    {
        $account = $this->account();
        app(AutomationRuleService::class)->create($this->ruleData());
        DB::beginTransaction();
        try {
            $this->importOrder($account);
            Queue::assertNothingPushed();
        } finally {
            DB::rollBack();
        }
        foreach (['orders', 'order_items', 'von_halsky_order_links', 'order_events', 'automation_runs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Queue::assertNothingPushed();
    }

    public function test_duplicate_import_and_job_do_not_repeat_a_rule_or_overwrite_later_edits(): void
    {
        $account = $this->account();
        app(AutomationRuleService::class)->create($this->ruleData());
        $order = $this->importOrder($account);
        $job = Queue::pushed(EvaluateAutomationEventJob::class)->sole();
        $job->handle(app(AutomationEngine::class));
        $job->handle(app(AutomationEngine::class));
        $this->assertDatabaseCount('automation_runs', 1);
        $run = $order->automationRuns()->sole();
        app(AutomationRunner::class)->execute($run, 0);
        $order->update(['notes' => 'Later manual edit']);
        app(AutomationRunner::class)->execute($run->fresh(), 0);
        $again = app(VonHalskyOrderImportService::class)->import($account, 'PG76P11', str_repeat('a', 64), [1], true);
        $this->assertSame($order->id, $again->id);
        $this->assertSame('Later manual edit', $again->notes);
        $this->assertSame(1, $order->events()->where('event_type', 'automation_order_modified')->count());
        Queue::assertPushed(EvaluateAutomationEventJob::class, 1);
        Http::assertSentCount(2);
    }

    public function test_historical_suppression_and_old_order_are_not_backfilled(): void
    {
        $account = $this->account();
        $order = $this->importOrder($account);
        $link = $order->vonHalskyOrderLink;
        $link->update(['automations_suppressed' => true]);
        $order->events()->sole()->update(['description' => 'Historical import without automations',
            'payload' => ['automations_suppressed' => true]]);
        $before = [$order->fresh()->getAttributes(), $link->fresh()->getAttributes(), $order->events()->sole()->getAttributes()];
        app(AutomationRuleService::class)->create($this->ruleData());
        app(VonHalskyOrderImportService::class)->import($account, 'PG76P11', str_repeat('a', 64), [1], true);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $link->fresh()->getAttributes(), $order->events()->sole()->getAttributes()]);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('automation_runs', 0);
        Http::assertSentCount(2);
    }

    public function test_missing_quantity_does_not_emit_events_until_confirmed_import(): void
    {
        $account = $this->account();
        app(AutomationRuleService::class)->create($this->ruleData());
        $response = $this->post(route('integrations.von-halsky.preview'), ['expected_lock_version' => 0, 'order_identifier' => 'PG76P11'])
            ->assertOk()->assertDontSee('confirmed_without_automations', false);
        $hash = $response->viewData('orderPreview')['import']['source_hash'];
        Queue::assertNothingPushed();
        $payload = ['order_identifier' => 'PG76P11', 'preview_hash' => $hash, 'quantities' => [1]];
        $this->post(route('integrations.von-halsky.import'), $payload)->assertSessionHasErrors('confirmed_quantities');
        $this->assertDatabaseCount('orders', 0);
        Queue::assertNothingPushed();
        $this->post(route('integrations.von-halsky.import'), $payload + ['confirmed_quantities' => 1])
            ->assertRedirect(route('orders.show', 1))->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Zamówienie Von Halsky jest dostępne w OMS.');
        Queue::assertPushed(EvaluateAutomationEventJob::class, 1);
    }

    public function test_automatic_import_uses_the_same_two_events_after_saving_the_link(): void
    {
        $account = $this->account(['order_import_enabled' => true,
            'order_import_started_at' => CarbonImmutable::parse($this->sourceOrder()['createdAt'])->subDay()]);
        foreach (['order.created', 'order.data_completed'] as $trigger) {
            app(AutomationRuleService::class)->create($this->ruleData($trigger));
        }
        $data = $this->sourceOrder();
        $data['orderLines'][0]['quantity'] = 1;
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['*' => Http::response($data)]);
        $date = CarbonImmutable::parse($data['createdAt']);
        $attempt = VonHalskyOrderImportAttempt::query()->create([
            'von_halsky_account_id' => $account->id, 'environment' => $account->environment->value,
            'organization_id' => $account->organization_id, 'external_order_id' => $data['id'],
            'external_created_at' => $date, 'external_updated_at' => $date, 'discovered_at' => now(), 'last_seen_at' => now(),
        ]);
        $service = app(VonHalskyOrderProcessingService::class);
        $service->process($account->id, $account->order_import_revision, $attempt->id);
        $service->process($account->id, $account->order_import_revision, $attempt->id);
        $this->assertSame('imported', $attempt->fresh()->status);
        $order = Order::query()->sole();
        Queue::assertPushed(EvaluateAutomationEventJob::class, 2);
        foreach (Queue::pushed(EvaluateAutomationEventJob::class) as $job) {
            $this->assertSame(['channel' => 'von_halsky', 'account_id' => $account->id], $job->eventPayload['order_source']);
            $job->handle(app(AutomationEngine::class));
        }
        $this->assertSame(2, $order->automationRuns()->count());
        $this->assertNotSame((new OrderCreated($order))->payload()['event_id'], (new OrderDataCompleted($order))->payload()['event_id']);
        Http::assertSentCount(2);
    }

    public function test_accepted_event_is_not_cancelled_by_later_connection_or_name_changes(): void
    {
        $account = $this->account();
        app(AutomationRuleService::class)->create($this->ruleData(selected: ['von_halsky:'.$account->id]));
        $order = $this->importOrder($account);
        $account->update(['name' => 'Renamed', 'is_active' => false, 'connection_revision' => 1]);
        $this->assertSame('Renamed (InPost Von Halsky)', app(AutomationOrderSources::class)->options()['von_halsky:'.$account->id]);
        Queue::pushed(EvaluateAutomationEventJob::class)->sole()->handle(app(AutomationEngine::class));
        $run = $order->automationRuns()->sole();
        app(AutomationRunner::class)->execute($run, 0);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame('Imported '.$order->id, $order->fresh()->notes);
        Http::assertSentCount(2);
    }

    public function test_previews_from_import_without_automations_require_refresh(): void
    {
        $account = $this->account();
        $data = app(VonHalskyClient::class)->order($account, 'PG76P11');
        $preview = app(VonHalskyOrderNormalizer::class)->normalize($account, $data, [1]);
        $context = ['account_id' => $account->id, 'organization_id' => $account->organization_id,
            'environment' => $account->environment->value, 'lock_version' => $account->lock_version,
            'connection_revision' => $account->connection_revision, 'initial_status_setting_id' => $account->initial_status_setting_id,
            'default_vat_rate' => $account->default_vat_rate, 'source' => $data, 'catalog_hash' => $preview['catalog_hash']];
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $canonical($item);
                }
            }

            return $value;
        };
        $legacyHash = hash_hmac('sha256', json_encode($canonical($context), JSON_THROW_ON_ERROR), config('app.key'));
        $this->assertNotSame($legacyHash, $preview['source_hash']);
        try {
            app(VonHalskyOrderImportService::class)->import($account, 'PG76P11', $legacyHash, [1], true);
            $this->fail('A preview for the old import contract must not authorize a changed import.');
        } catch (VonHalskyApiException) {
            $this->assertDatabaseCount('orders', 0);
            Queue::assertNothingPushed();
        }
    }

    private function importOrder(VonHalskyAccount $account): Order
    {
        $preview = app(VonHalskyOrderPreviewService::class)->preview($account, 'PG76P11', [1])['import'];

        return app(VonHalskyOrderImportService::class)->import($account, 'PG76P11', $preview['source_hash'], [1], true);
    }

    private function ruleData(string $trigger = 'order.data_completed', array $selected = ['von_halsky'], bool $active = true): array
    {
        return ['name' => 'Halsky '.$trigger, 'trigger' => $trigger, 'is_active' => $active,
            'conditions' => $selected === [] ? [] : [['field' => 'integration_source', 'operator' => 'in', 'value' => $selected]],
            'actions' => [['type' => AutomationCatalog::ACTION_MODIFY_ORDER,
                'configuration' => ['field' => 'notes', 'value' => 'Imported [id_zamowienia]'], 'stop_on_error' => true]]];
    }
}
