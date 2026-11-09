<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderSavedView;
use App\Support\OrderListSort;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Shipments\Models\Shipment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    #[DataProvider('sortModes')]
    public function test_all_sort_modes_and_neighbors_follow_the_same_order(string $key, string $direction): void
    {
        [$orders, $ascending, $descending] = $this->scenario($key);
        $expected = array_map(fn (int $index) => $orders[$index]->id, $direction === 'asc' ? $ascending : $descending);
        $sort = OrderListSort::fromArray(['sort' => $key, 'direction' => $direction]);
        $statuses = ['z_first' => 'First', 'a_last' => 'Last'];
        $query = $sort->apply(Order::query(), $statuses);
        $this->assertSame($expected, (clone $query)->pluck('id')->all());
        foreach ($expected as $position => $id) {
            $neighbors = $sort->adjacentIds($orders[array_search($id, array_column($orders, 'id'), true)], $query, $statuses);
            $this->assertSame(['previous' => $expected[$position - 1] ?? null, 'next' => $expected[$position + 1] ?? null], $neighbors);
        }
        Http::assertNothingSent();
    }

    public static function sortModes(): array
    {
        $cases = [];
        foreach (array_keys(OrderListSort::options()) as $key) {
            foreach (['asc', 'desc'] as $direction) {
                $cases[$key.'-'.$direction] = [$key, $direction];
            }
        }

        return $cases;
    }

    public function test_default_id_sort_is_independent_of_creation_date(): void
    {
        $first = $this->order(['created_at' => '2030-01-01']);
        $last = $this->order(['created_at' => '2020-01-01']);
        $this->get(route('orders.index'))->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$last->id, $first->id]);
    }

    public function test_all_modes_compile_with_mysql_grammar_without_a_mysql_server(): void
    {
        $default = DB::getDefaultConnection();
        // PDO is used only to quote pretend-log bindings; no SQL is executed on it.
        $connection = new MySqlConnection(new \PDO('sqlite::memory:'),
            'sorting_test', '', ['name' => 'sorting_mysql', 'driver' => 'mysql']);
        config(['database.connections.sorting_mysql' => ['driver' => 'mysql']]);
        DB::extend('sorting_mysql', fn () => $connection);
        DB::setDefaultConnection('sorting_mysql');
        try {
            $queries = $connection->pretend(function (): void {
                foreach (array_keys(OrderListSort::options()) as $key) {
                    $sort = OrderListSort::fromArray(['sort' => $key, 'direction' => 'asc']);
                    $query = $sort->apply(Order::query(), ['new' => 'New']);
                    (clone $query)->get();
                    $sort->adjacentIds((new Order)->forceFill(['id' => 123]), $query, ['new' => 'New']);
                }
            });
            $this->assertCount(39, $queries);
            foreach ($queries as $query) {
                $this->assertStringContainsString('`orders`', $query['query']);
                $this->assertStringNotContainsString('DROP', $query['query']);
            }
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('sorting_mysql');
        }
    }

    public function test_sorting_applies_before_pagination_and_keeps_filters(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->order(['source' => 'allegro', 'total_gross' => (string) ($i + 1)]);
        }
        $this->order(['source' => 'prestashop', 'total_gross' => '0.01']);
        $response = $this->get(route('orders.index', ['source' => 'allegro', 'customer' => 'Test', 'sort' => 'total', 'direction' => 'asc', 'page' => 2]))
            ->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 22);
        $this->assertSame(['21.00', '22.00'], $response->viewData('orders')->pluck('total_gross')->all());
        $this->assertSame('total', $response->viewData('orderListContext')['sort']);
    }

    public function test_menu_keeps_filters_resets_page_and_is_after_bulk_status(): void
    {
        $this->order();
        $response = $this->get(route('orders.index', ['status' => 'new', 'source' => 'allegro', 'sort' => 'total', 'direction' => 'asc', 'page' => 2, 'per_page' => 50]))->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $toolbars = $xpath->query('//*[@data-list-toolbar-slot or @data-list-toolbar-dock]');
        $this->assertSame(2, $toolbars->length);
        foreach ($toolbars as $toolbar) {
            $menu = './/ul[contains(@class,"orders-sort-menu")]';
            $this->assertSame(13, $xpath->query($menu.'//a[@data-order-sort-key]', $toolbar)->length);
            $this->assertSame('total', $xpath->query($menu.'//a[@data-order-sort-key and contains(@class,"active")]', $toolbar)->item(0)->getAttribute('data-order-sort-key'));
            $target = $xpath->query($menu.'//a[@data-order-sort-key="customer"]', $toolbar)->item(0)->getAttribute('href');
            parse_str(parse_url($target, PHP_URL_QUERY), $query);
            $this->assertSame(['status' => 'new', 'source' => 'allegro', 'sort' => 'customer', 'direction' => 'asc', 'page' => '1', 'per_page' => '50'], $query);
            $this->assertGreaterThan(0, $xpath->query('.//*[@data-order-sort-toggle]/parent::div/preceding-sibling::div[1]//button[@data-bulk-status-submit]', $toolbar)->length);
        }
        $clear = $xpath->query('//a[contains(@class,"orders-filter-action-button")]')->item(0)->getAttribute('href');
        parse_str(parse_url($clear, PHP_URL_QUERY), $clearQuery);
        $this->assertSame('total', $clearQuery['sort']);
        $this->assertArrayNotHasKey('source', $clearQuery);
    }

    public function test_saved_view_persists_sorting_and_rejects_invalid_sql_input(): void
    {
        $this->postJson(route('orders.saved-views.store'), ['name' => 'Sorted', 'filters' => ['sort' => 'total', 'direction' => 'asc']])
            ->assertCreated()->assertJsonPath('view.filters.sort', 'total');
        $saved = OrderSavedView::query()->sole();
        $this->get(route('orders.index', $saved->filters))->assertOk()->assertViewHas('activeSavedViewId', $saved->id);
        $this->postJson(route('orders.saved-views.store'), ['name' => 'Invalid', 'filters' => ['sort' => 'id; SELECT 1', 'direction' => 'sideways']])
            ->assertUnprocessable()->assertJsonValidationErrors(['filters.sort', 'filters.direction']);
    }

    public function test_sorting_does_not_change_selection_scope_or_count_as_a_filter(): void
    {
        $this->order();
        $first = $this->get(route('orders.index'))->assertOk();
        $second = $this->get(route('orders.index', ['sort' => 'customer', 'direction' => 'asc']))->assertOk()->assertViewHas('hasActiveFilters', false);
        preg_match('/data-selection-scope-key="([^"]+)"/', $first->getContent(), $a);
        preg_match('/data-selection-scope-key="([^"]+)"/', $second->getContent(), $b);
        $this->assertSame($a[1], $b[1]);
    }

    public function test_sort_context_survives_return_and_status_change(): void
    {
        $first = $this->order(['total_gross' => '10.00']);
        $current = $this->order(['total_gross' => '20.00']);
        $context = ['status' => 'new', 'sort' => 'total', 'direction' => 'asc', 'page' => 1, 'per_page' => 50];
        $current->update(['status' => 'shipped']);
        $response = $this->get(route('orders.show', ['order' => $current, 'list' => $context]))->assertOk();
        $returned = $this->get($response->viewData('ordersListUrl'))->assertOk();
        $this->assertSame('total', $returned->viewData('orderSorting')->key);
        $this->assertSame('asc', $returned->viewData('orderSorting')->direction);
        $this->assertSame([$first->id], $returned->viewData('orders')->pluck('id')->all());
    }

    private function scenario(string $key): array
    {
        $low = $high = $tie = $missing = [];
        $nullable = true;
        switch ($key) {
            case 'id':
                return [[$this->order(), $this->order()], [0, 1], [1, 0]];
            case 'store_number':
                $low = ['source' => 'prestashop', 'external_id' => '2'];
                $high = ['source' => 'prestashop', 'external_id' => '10'];
                $tie = ['source' => 'prestashop', 'external_id' => '02'];
                break;
            case 'ordered_at':
                $low = ['purchased_at' => '2025-01-01'];
                $high = ['purchased_at' => '2027-01-01'];
                $tie = $low;
                $missing = ['purchased_at' => null, 'created_at' => null];
                break;
            case 'status_at':
                $low = ['status_changed_at' => '2025-01-01'];
                $high = ['status_changed_at' => '2027-01-01'];
                $tie = $low;
                $missing = ['status_changed_at' => null, 'created_at' => null];
                break;
            case 'total':
            case 'delivery_cost':
                $column = $key === 'total' ? 'total_gross' : 'delivery_cost_gross';
                $low = [$column => '1.01'];
                $high = [$column => '12.13'];
                $tie = $low;
                $nullable = false;
                break;
            case 'shipping_method':
                $low = ['shipping_method' => 'InPost'];
                $high = ['shipping_method' => 'UPS'];
                $tie = ['shipping_method' => 'inpost'];
                break;
            case 'status':
                $low = ['status' => 'z_first'];
                $high = ['status' => 'a_last'];
                $tie = $low;
                break;
            case 'payment':
                return [[$this->order(['paid_amount' => '0.00']), $this->order(['paid_amount' => '100.00']),
                    $this->order(['paid_amount' => '0.00']), $this->order(['paid_amount' => '50.00'])], [0, 2, 3, 1], [1, 3, 2, 0]];
            case 'customer':
            case 'login':
                $column = $key === 'customer' ? 'shipping_name' : 'customer_login';
                $low = [$column => 'anna'];
                $high = [$column => 'zenon'];
                $tie = [$column => 'ANNA'];
                $missing = ['shipping_name' => '', 'customer_login' => null];
                break;
            case 'allegro_transaction':
                $low = ['source' => 'allegro', 'external_id' => 'aaaa'];
                $high = ['source' => 'allegro', 'external_id' => 'zzzz'];
                $tie = ['source' => 'allegro', 'external_id' => 'AAAA'];
                break;
        }
        $orders = [$this->order($low), $this->order($high), $this->order($tie)];
        if ($nullable) {
            $orders[] = $this->order($missing);
        }
        if ($key === 'tracking_number') {
            foreach ([0 => 'beta', 1 => 'zeta', 2 => 'BETA'] as $index => $tracking) {
                $this->tracking($orders[$index], 'zzzz-old', '2026-01-01');
                $this->tracking($orders[$index], $tracking, '2026-02-01');
                $this->tracking($orders[$index], '', '2026-03-01');
            }
        }

        return [$orders, $nullable ? [0, 2, 1, 3] : [0, 2, 1], $nullable ? [1, 2, 0, 3] : [1, 2, 0]];
    }

    private function order(array $values = []): Order
    {
        $order = Order::query()->create(array_replace(['source' => 'manual', 'status' => 'new', 'shipping_name' => 'Test',
            'currency' => 'PLN', 'total_gross' => '100.00', 'paid_amount' => '0.00', 'delivery_cost_gross' => '0.00'], $values));
        $order->timestamps = false;
        $order->forceFill(['created_at' => array_key_exists('created_at', $values) ? $values['created_at'] : '2026-01-01 00:00:00'])->save();

        return $order->fresh();
    }

    private function tracking(Order $order, string $number, string $date): void
    {
        $shipment = $order->shipments()->create(['provider' => 'inpost_courier', 'service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
            'tracking_number' => $number, 'status' => 'confirmed', 'request_uuid' => (string) Str::uuid()]);
        $shipment->timestamps = false;
        $shipment->forceFill(['created_at' => $date])->save();
    }
}
