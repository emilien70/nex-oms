<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ReturnStatusSetting;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderReturnsCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(12, 0));
    }

    public function test_order_without_returns_keeps_an_empty_refresh_target_without_a_card(): void
    {
        $order = $this->order();

        $this->get(route('orders.show', $order))->assertOk()
            ->assertSee('data-order-returns', false)
            ->assertDontSee('aria-labelledby="orderReturnsTitle"', false);
        $state = $this->getJson(route('orders.state', $order))->assertOk();
        $this->assertSame('', trim($state->json('fragments.returns')));
        $this->assertNotEmpty($state->json('returns_signature'));
    }

    public function test_card_is_directly_below_product_controls_and_displays_stored_return_data(): void
    {
        $order = $this->order('allegro');
        $record = $this->createReturn($order, ['account_name' => 'multi-click', 'reference_number' => '4Y96/2026', 'total_value' => '549.00']);
        $record->items()->createMany([$this->item(0, 4), $this->item(1, 3)]);

        $response = $this->get(route('orders.show', $order))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//*[@data-order-returns]/preceding-sibling::*[1][contains(@class, "products-toolbar")]')->length);
        $this->assertSame(1, $xpath->query('//h2[@id="orderReturnsTitle"]')->length);
        $this->assertSame(1, $xpath->query('//section[contains(@class, "order-returns-card")]')->length);
        $this->assertSame(7, $xpath->query('//table[contains(@class, "order-returns-table")]/thead/tr/th')->length);
        $row = $xpath->query('//*[@data-order-return-id="'.$record->id.'"]')->item(0);
        $cells = $row->getElementsByTagName('td');
        $this->assertStringContainsString('(4Y96/2026)', $cells->item(0)->textContent);
        $this->assertStringContainsString('multi-click', $cells->item(1)->textContent);
        $this->assertSame('2', trim($cells->item(2)->textContent));
        $this->assertSame('549,00 PLN', trim($cells->item(3)->textContent));
        $this->assertSame('Nowe', trim($cells->item(4)->textContent));
        $this->assertSame('01.11.2026 12:00', trim($cells->item(5)->textContent));
        $this->assertSame('03.11.2026 12:00', trim($cells->item(6)->textContent));
        $this->assertSame(route('returns.show', $record), $cells->item(0)->getElementsByTagName('a')->item(0)->getAttribute('href'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('sources')]
    public function test_card_supports_all_order_sources_and_keeps_the_account_snapshot(string $source, string $label): void
    {
        $order = $this->order($source);
        $record = $this->createReturn($order);

        $fragment = $this->getJson(route('orders.state', $order))->assertOk()->json('fragments.returns');
        $this->assertStringContainsString($label, $fragment);
        $record->update(['account_name' => 'Historyczna integracja']);
        $fragment = $this->getJson(route('orders.state', $order))->assertOk()->json('fragments.returns');
        $this->assertStringContainsString('Historyczna integracja', $fragment);
    }

    public static function sources(): array
    {
        return [['manual', 'Ręczne'], ['allegro', 'Allegro'], ['prestashop', 'PrestaShop'], ['von_halsky', 'InPost Von Halsky']];
    }

    public function test_multiple_returns_are_newest_first_with_a_stable_tie_breaker_and_no_other_orders(): void
    {
        $order = $this->order();
        $old = $this->createReturn($order, ['submitted_at' => now()->subDays(5)]);
        $new = $this->createReturn($order);
        $newerId = $this->createReturn($order);
        $foreign = $this->createReturn($this->order());

        $html = $this->get(route('orders.show', $order))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $rows = $xpath->query('//*[@data-order-return-id]');
        $this->assertSame([$newerId->id, $new->id, $old->id], array_map(
            fn ($row) => (int) $row->getAttribute('data-order-return-id'), iterator_to_array($rows),
        ));
        $this->assertSame(0, $xpath->query('//*[@data-order-return-id="'.$foreign->id.'"]')->length);
    }

    public function test_status_uses_oms_settings_instead_of_the_external_allegro_status(): void
    {
        $order = $this->order();
        $status = ReturnStatusSetting::query()->create(['code' => 'inspection', 'name' => 'Kontrola towaru', 'color' => '#123456', 'sort_order' => 10]);
        $this->createReturn($order, ['local_status_id' => $status->id, 'status' => 'FINISHED']);

        $fragment = $this->getJson(route('orders.state', $order))->assertOk()->json('fragments.returns');

        $this->assertStringContainsString('Kontrola towaru', $fragment);
        $this->assertStringContainsString('background: #123456; color: #ffffff;', $fragment);
        $this->assertStringNotContainsString('Zakończony', $fragment);
    }

    public function test_missing_optional_values_and_html_input_are_presented_safely(): void
    {
        $order = $this->order();
        $record = $this->createReturn($order, ['account_name' => '<script>source()</script>', 'reference_number' => '<img src=x onerror=alert(1)>', 'local_status_id' => null, 'local_status_changed_at' => null]);
        $before = $record->fresh()->getRawOriginal();

        $fragment = $this->getJson(route('orders.state', $order))->assertOk()->json('fragments.returns');

        $this->assertStringContainsString('&lt;script&gt;source()&lt;/script&gt;', $fragment);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $fragment);
        $this->assertStringNotContainsString('<script>source()', $fragment);
        $this->assertStringNotContainsString('<img src=x', $fragment);
        $this->assertStringContainsString('Brak statusu', $fragment);
        $this->assertStringContainsString('...', $fragment);
        $this->assertSame($before, $record->fresh()->getRawOriginal());
    }

    public function test_live_refresh_detects_creation_status_item_count_and_removal_without_touching_order(): void
    {
        $order = $this->order();
        $original = $order->fresh()->getRawOriginal();
        $state = $this->getJson(route('orders.state', $order))->assertOk();
        $signature = $state->json('returns_signature');
        $unchanged = $this->stateWithSignature($order, $signature);
        $this->assertArrayNotHasKey('returns', $unchanged->json('fragments'));

        $record = $this->createReturn($order);
        $created = $this->stateWithSignature($order, $signature);
        $this->assertNotSame($signature, $created->json('returns_signature'));
        $this->assertStringContainsString('data-order-return-id="'.$record->id.'"', $created->json('fragments.returns'));
        $record->items()->create($this->item(0, 5));
        $withItem = $this->stateWithSignature($order, $created->json('returns_signature'));
        $this->assertNotSame($created->json('returns_signature'), $withItem->json('returns_signature'));

        $status = ReturnStatusSetting::query()->where('code', 'received')->firstOrFail();
        $record->update(['local_status_id' => $status->id, 'local_status_changed_at' => now()]);
        $changed = $this->stateWithSignature($order, $withItem->json('returns_signature'));
        $this->assertStringContainsString('Odebrane', $changed->json('fragments.returns'));
        $this->assertNotSame($withItem->json('returns_signature'), $changed->json('returns_signature'));
        $record->delete();
        $removed = $this->stateWithSignature($order, $changed->json('returns_signature'));
        $this->assertSame('', trim($removed->json('fragments.returns')));
        $this->assertSame($signature, $removed->json('returns_signature'));
        $this->assertSame($original, $order->fresh()->getRawOriginal());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_local_status_configuration_changes_and_explicit_refresh_are_detected(): void
    {
        $order = $this->order();
        $record = $this->createReturn($order);
        $signature = $this->getJson(route('orders.state', $order))->assertOk()->json('returns_signature');
        $record->localStatus->update(['name' => 'Nowy opis', 'color' => '#456789']);

        $changed = $this->stateWithSignature($order, $signature);
        $this->assertStringContainsString('Nowy opis', $changed->json('fragments.returns'));
        $this->assertNotSame($signature, $changed->json('returns_signature'));
        $this->getJson(route('orders.state', ['order' => $order, 'returns_signature' => $changed->json('returns_signature'), 'fragments' => 'returns']))
            ->assertOk()->assertJsonStructure(['fragments' => ['returns']]);
    }

    public function test_return_queries_do_not_grow_with_the_number_of_rows(): void
    {
        $order = $this->order();
        $this->createReturn($order)->items()->create($this->item(0, 1));
        $queries = [];
        $recording = false;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$recording): void {
            if ($recording && (str_contains($query->sql, 'order_return') || str_contains($query->sql, 'return_status_settings'))) {
                $queries[] = $query->sql;
            }
        });
        $recording = true;
        $this->getJson(route('orders.state', $order))->assertOk();
        $recording = false;
        $singleRowQueries = count($queries);
        $this->assertGreaterThan(0, $singleRowQueries);
        for ($i = 0; $i < 10; $i++) {
            $this->createReturn($order)->items()->create($this->item(0, 2));
        }
        $queries = [];

        $recording = true;
        $this->getJson(route('orders.state', $order))->assertOk();
        $recording = false;

        $this->assertCount($singleRowQueries, $queries);
    }

    private function stateWithSignature(Order $order, string $signature): TestResponse
    {
        return $this->getJson(route('orders.state', ['order' => $order, 'returns_signature' => $signature]))->assertOk();
    }

    private function order(string $source = 'manual'): Order
    {
        return Order::query()->create(['source' => $source, 'status' => 'new', 'currency' => 'PLN', 'total_gross' => '549.00', 'paid_amount' => '0.00', 'payment_status' => 'unpaid']);
    }

    private function createReturn(Order $order, array $overrides = []): OrderReturn
    {
        return OrderReturn::query()->create(array_replace([
            'order_id' => $order->id, 'source' => $order->source, 'currency' => 'PLN', 'total_value' => '10.00',
            'submitted_at' => now()->subDays(3), 'local_status_id' => ReturnStatusSetting::initial()->id,
            'local_status_changed_at' => now()->subDay(),
        ], $overrides));
    }

    private function item(int $position, int $quantity): array
    {
        return ['position' => $position, 'name' => 'Produkt', 'quantity' => $quantity, 'unit_price' => '10.00', 'total_value' => (string) ($quantity * 10), 'currency' => 'PLN', 'reason' => 'Pomyłka'];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }
}
