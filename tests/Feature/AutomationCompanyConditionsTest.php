<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\GusRegonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Automation\Jobs\ExecuteAutomationRunJob;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationConditionEvaluator;
use Modules\Automation\Services\AutomationEngine;
use Modules\Automation\Services\AutomationGusNipCheck;
use Modules\Automation\Services\OrderAutomationHistory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutomationCompanyConditionsTest extends TestCase
{
    use RefreshDatabase;

    private const NIP = '9876543210';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
        config(['services.gus.key' => 'test-gus-key', 'services.gus.url' => 'https://gus.example.test/bir']);
    }

    public function test_editor_lists_company_fields_and_presets_and_saves_local_and_gus_conditions(): void
    {
        $conditions = [
            ['field' => 'billing_company_name', 'operator' => 'is_empty'],
            ['field' => 'shipping_company_name', 'operator' => 'not_contains', 'value' => 'Example'],
            ['field' => 'billing_tax_id', 'operator' => 'equals', 'value' => '987-654-32-10'],
            $this->gus(AutomationGusNipCheck::NEGATIVE),
        ];
        $this->postJson(route('orders.automatic-actions.store'), $this->ruleData($conditions))
            ->assertCreated();
        $this->assertSame($conditions, AutomationRule::query()->sole()->conditions);

        $this->get(route('orders.automatic-actions.index'))->assertOk()
            ->assertSee('Nazwa firmy (faktura)')
            ->assertSee('Nazwa firmy (adres dostawy)')
            ->assertSee('Niepoprawny NIP (GUS)');
        Http::assertNothingSent();
    }

    public function test_validation_rejects_values_for_empty_checks_and_invalid_presets(): void
    {
        $conditions = [
            ['field' => 'billing_company_name', 'operator' => 'is_empty', 'value' => 'Spolka'],
            ['field' => 'shipping_company_name', 'operator' => 'contains', 'value' => '   '],
            ['field' => 'billing_tax_id', 'operator' => 'contains', 'value' => AutomationGusNipCheck::NEGATIVE],
            ['field' => 'billing_tax_id', 'operator' => 'equals', 'value' => ' -- '],
            ['field' => 'shipping_method', 'operator' => 'ends_with', 'value' => 'Kurier'],
        ];
        $this->postJson(route('orders.automatic-actions.store'), $this->ruleData($conditions))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'conditions.0.value', 'conditions.1.value', 'conditions.2.value', 'conditions.3.value', 'conditions.4.operator',
            ]);
    }

    public function test_text_comparisons_use_the_correct_field_and_never_match_empty_values_by_negation(): void
    {
        $order = new Order(['billing_company_name' => '  Firma Łódź ', 'shipping_company_name' => 'Magazyn']);
        $evaluator = app(AutomationConditionEvaluator::class);
        foreach ([
            ['billing_company_name', 'contains', 'ŁÓDŹ', true],
            ['billing_company_name', 'not_contains', 'Magazyn', true],
            ['billing_company_name', 'equals', 'firma ŁÓDŹ', true],
            ['billing_company_name', 'starts_with', 'firma', true],
            ['billing_company_name', 'ends_with', 'ŁÓDŹ', true],
            ['billing_company_name', 'is_not_empty', null, true],
            ['shipping_company_name', 'equals', 'Firma Łódź', false],
            ['shipping_company_name', 'not_equals', 'Firma Łódź', true],
            ['shipping_company_name', 'is_empty', null, false],
        ] as [$field, $operator, $value, $matches]) {
            $this->assertSame($matches, $evaluator->matches($order, [], [compact('field', 'operator', 'value')]));
        }
        $order->shipping_company_name = '  ';
        foreach (['is_empty' => true, 'not_contains' => false, 'not_equals' => false] as $operator => $matches) {
            $this->assertSame($matches, $evaluator->matches($order, [], [
                ['field' => 'shipping_company_name', 'operator' => $operator, 'value' => 'Magazyn'],
            ]));
        }

        $order->billing_tax_id = '01-23 45';
        foreach (['012345' => true, '12345' => false] as $value => $matches) {
            $this->assertSame($matches, $evaluator->matches($order, [], [
                ['field' => 'billing_tax_id', 'operator' => 'equals', 'value' => (string) $value],
            ]));
        }
        Http::assertNothingSent();
    }

    #[DataProvider('knownGusResults')]
    public function test_gus_result_is_used_once_and_reused_across_events(array $response, string $result, string $preset): void
    {
        $this->mock(GusRegonService::class)->shouldReceive('findCompaniesByNip')->once()->with(self::NIP, 2)->andReturn($response);
        $positive = $this->createRule($this->gus(AutomationGusNipCheck::POSITIVE));
        $negative = $this->createRule($this->gus(AutomationGusNipCheck::NEGATIVE));
        $order = $this->order(['billing_tax_id' => 'PL 987-654-32-10']);
        $other = $this->order();
        $this->evaluate($order);
        $this->evaluate($other);

        $matched = $preset === AutomationGusNipCheck::POSITIVE ? $positive : $negative;
        $runs = Queue::pushed(ExecuteAutomationRunJob::class)->map(fn ($job) => $job->run);
        $this->assertSame([$matched->id, $matched->id], $runs->pluck('automation_rule_id')->all());
        $this->assertSame([$result, $result], $runs->pluck('rule_snapshot.gus_nip_check.result')->all());
        $message = $result === 'found' ? 'Znaleziono podmiot w GUS.' : 'Nie znaleziono podmiotu w GUS.';
        $this->assertSame($message, app(OrderAutomationHistory::class)->forOrder($order)->items()[0]['gus_result']);
        $snapshot = $runs->first()->rule_snapshot;
        $this->assertStringNotContainsString(self::NIP, json_encode($snapshot));
        $evaluator = app(AutomationConditionEvaluator::class);
        $this->assertTrue($evaluator->matches($order, [], $snapshot['conditions'], $snapshot['gus_nip_check']));
        $order->billing_tax_id = '1234563218';
        $this->assertFalse($evaluator->matches($order, [], $snapshot['conditions'], $snapshot['gus_nip_check']));
    }

    public static function knownGusResults(): array
    {
        return [
            'found' => [[['name' => 'Company']], 'found', AutomationGusNipCheck::POSITIVE],
            'not found' => [[], 'not_found', AutomationGusNipCheck::NEGATIVE],
        ];
    }

    public function test_gus_outage_matches_negative_only_and_does_not_cache_a_negative_result(): void
    {
        Http::fake(['https://gus.example.test/bir' => Http::failedConnection()]);
        $local = $this->createRule(['field' => 'billing_tax_id', 'operator' => 'is_not_empty']);
        $this->createRule($this->gus(AutomationGusNipCheck::POSITIVE));
        $negative = $this->createRule($this->gus(AutomationGusNipCheck::NEGATIVE));
        $order = $this->order();

        $this->evaluate($order);

        $runs = Queue::pushed(ExecuteAutomationRunJob::class)->map(fn ($job) => $job->run);
        $this->assertSame([$local->id, $negative->id], $runs->pluck('automation_rule_id')->all());
        $this->assertArrayNotHasKey('gus_nip_check', $runs->first()->rule_snapshot);
        $this->assertSame('unavailable', data_get($runs->last()->rule_snapshot, 'gus_nip_check.result'));
        $this->assertSame('Nie udało się sprawdzić NIP-u w GUS.', app(OrderAutomationHistory::class)->forOrder($order)->items()[0]['gus_result']);

        $this->mock(GusRegonService::class)->shouldReceive('findCompaniesByNip')->once()->with(self::NIP, 2)->andReturn([['name' => 'Company']]);
        $this->assertSame('found', app(AutomationGusNipCheck::class)->check($order)['result']);
    }

    public function test_skips_gus_for_inapplicable_nips_and_failed_local_conditions(): void
    {
        $this->mock(GusRegonService::class)->shouldNotReceive('findCompaniesByNip');
        $rule = $this->createRule($this->gus(AutomationGusNipCheck::NEGATIVE));
        foreach ([
            ['billing_tax_id' => null],
            ['billing_tax_id' => '1234567890'],
            ['billing_tax_id' => '0000000000'],
            ['billing_tax_id' => 'DE'.self::NIP],
            ['billing_country_code' => 'DE'],
            ['billing_country_code' => null],
        ] as $overrides) {
            $this->evaluate($this->order($overrides));
        }
        $rule->update(['conditions' => [
            $this->gus(AutomationGusNipCheck::NEGATIVE),
            ['field' => 'billing_company_name', 'operator' => 'equals', 'value' => 'Inna firma'],
        ]]);
        $this->evaluate($this->order());
        Queue::assertNothingPushed();
    }

    private function ruleData(array $conditions): array
    {
        return [
            'name' => 'Warunek testowy',
            'trigger' => 'order.status_changed',
            'conditions' => $conditions,
            'is_active' => true,
            'actions' => [[
                'type' => AutomationCatalog::ACTION_CHANGE_STATUS,
                'configuration' => ['status' => Order::STATUS_PENDING],
                'stop_on_error' => true,
            ]],
        ];
    }

    private function createRule(array $condition): AutomationRule
    {
        return AutomationRule::create(collect($this->ruleData([$condition]))->except('actions')->all());
    }

    private function order(array $overrides = []): Order
    {
        return Order::create($overrides + [
            'status' => Order::STATUS_NEW,
            'source' => 'manual',
            'currency' => 'PLN',
            'billing_tax_id' => self::NIP,
            'billing_country_code' => 'PL',
        ]);
    }

    private function gus(string $preset): array
    {
        return ['field' => 'billing_tax_id', 'operator' => 'equals', 'value' => $preset];
    }

    private function evaluate(Order $order): void
    {
        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'order_id' => $order->id,
            'event_id' => (string) Str::uuid(),
        ]);
    }
}
