<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderSavedView;
use App\Support\OrderListNavigationContext;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Shipments\Models\Shipment;
use Tests\TestCase;

class OrderListContextNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_entering_an_order_preserves_filters_and_the_original_page(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->order('pending');
        }
        $query = ['status' => 'pending', 'source' => 'allegro', 'payment' => 'unpaid', 'page' => 2, 'per_page' => 20];
        $list = $this->get(route('orders.index', $query))->assertOk();
        $order = $list->viewData('orders')->first();
        $context = $list->viewData('orderListContext');
        $url = route('orders.show', OrderListNavigationContext::orderParameters($order->id, $context));
        $list->assertSee(e($url), false);
        $details = $this->get($url)->assertOk();
        $returnUrl = route('orders.index', $query + ['list_return' => 1]);
        $this->assertSame($returnUrl, $details->viewData('ordersListUrl'));
        $returned = $this->get($returnUrl)->assertOk()->assertViewHas('hasActiveFilters', true)
            ->assertSee('class="collapse show" id="ordersFiltersPanel"', false);
        $this->assertSame(2, $returned->viewData('orders')->currentPage());
        $this->assertSame('allegro', $returned->viewData('filters')['source']);
        $this->assertSame('unpaid', $returned->viewData('filters')['payment']);
        Http::assertNothingSent();
    }

    public function test_arrows_follow_filtered_results_and_keep_the_original_page_context(): void
    {
        $newer = $this->order('pending', '2026-10-15 12:00:00');
        $excluded = $this->order('pending', '2026-10-15 11:30:00');
        $excluded->update(['source' => 'prestashop']);
        $current = $this->order('pending', '2026-10-15 11:00:00');
        $older = $this->order('pending', '2026-10-15 10:00:00');
        $context = ['status' => 'pending', 'source' => 'allegro', 'sort' => 'ordered_at', 'direction' => 'desc', 'page' => 2, 'per_page' => 20];
        $details = $this->get($this->orderUrl($current, $context))->assertOk();
        $this->assertSame($this->orderUrl($newer, $context), $details->viewData('previousOrderUrl'));
        $this->assertSame($this->orderUrl($older, $context), $details->viewData('nextOrderUrl'));
        $next = $this->get($details->viewData('nextOrderUrl'))->assertOk();
        $this->assertSame($details->viewData('ordersListUrl'), $next->viewData('ordersListUrl'));
        $this->assertNull($next->viewData('nextOrderUrl'));
    }

    public function test_filtered_navigation_retains_id_tie_breaker_for_identical_dates(): void
    {
        $older = $this->order();
        $current = $this->order();
        $newer = $this->order();
        $context = ['status' => 'new', 'source' => 'allegro', 'page' => 1, 'per_page' => 20];
        $details = $this->get($this->orderUrl($current, $context))->assertOk();
        $this->assertSame($this->orderUrl($newer, $context), $details->viewData('previousOrderUrl'));
        $this->assertSame($this->orderUrl($older, $context), $details->viewData('nextOrderUrl'));
    }

    public function test_arrows_cross_real_page_boundary_but_return_to_the_starting_page(): void
    {
        $orders = [];
        for ($i = 0; $i < 22; $i++) {
            $orders[] = $this->order();
        }
        $query = ['status' => 'new', 'source' => 'allegro', 'page' => 1, 'per_page' => 20];
        $list = $this->get(route('orders.index', $query))->assertOk();
        $this->assertSame($orders[2]->id, $list->viewData('orders')->last()->id);
        $details = $this->get($this->orderUrl($orders[2], $query))->assertOk();
        $this->assertSame($this->orderUrl($orders[1], $query), $details->viewData('nextOrderUrl'));
        $next = $this->get($details->viewData('nextOrderUrl'))->assertOk();
        $this->assertSame($this->orderUrl($orders[0], $query), $next->viewData('nextOrderUrl'));
        $this->assertSame($details->viewData('ordersListUrl'), $next->viewData('ordersListUrl'));
        $this->assertSame(1, $this->get($next->viewData('ordersListUrl'))->assertOk()->viewData('orders')->currentPage());
    }

    public function test_arrows_apply_payment_and_customer_filters_together(): void
    {
        $matching = $this->order('new', '2026-10-15 12:00:00');
        $matching->update(['shipping_name' => 'Anna Test']);
        $paid = $this->order('new', '2026-10-15 11:45:00');
        $paid->update(['shipping_name' => 'Anna Test', 'paid_amount' => '100.00']);
        $differentBuyer = $this->order('new', '2026-10-15 11:30:00');
        $differentBuyer->update(['shipping_name' => 'Jan Test']);
        $current = $this->order();
        $current->update(['shipping_name' => 'Anna Test']);
        $context = ['status' => 'new', 'customer' => 'Anna', 'payment' => 'unpaid', 'sort' => 'ordered_at', 'direction' => 'desc', 'page' => 1, 'per_page' => 50];
        $details = $this->get($this->orderUrl($current, $context))->assertOk();
        $this->assertSame($this->orderUrl($matching, $context), $details->viewData('previousOrderUrl'));
        $this->assertNull($details->viewData('nextOrderUrl'));
    }

    public function test_status_change_does_not_change_origin_or_filter_other_status_links(): void
    {
        $current = $this->order();
        $other = $this->order('new', '2026-10-15 10:00:00');
        $context = ['status' => 'new', 'source' => 'allegro', 'sort' => 'ordered_at', 'direction' => 'desc', 'page' => 1, 'per_page' => 20];
        $url = $this->orderUrl($current, $context);
        $original = $this->get($url)->assertOk()->viewData('ordersListUrl');
        $current->update(['status' => 'shipped']);
        $details = $this->get($url)->assertOk();
        $this->assertSame($original, $details->viewData('ordersListUrl'));
        $this->assertSame('new', $details->viewData('orderNavigationStatus'));
        $this->assertSame($this->orderUrl($other, $context), $details->viewData('nextOrderUrl'));
        $xpath = $this->xpath($details->getContent());
        $origin = $xpath->query('//nav[@id="orderStatusNavigation"]/a[contains(@class,"active")]')->item(0);
        $this->assertSame($original, $origin?->getAttribute('href'));
        $shippedUrl = route('orders.index', ['status' => 'shipped']);
        $this->assertSame(1, $xpath->query('//nav[@id="orderStatusNavigation"]/a[@href="'.$shippedUrl.'"]')->length);
        $returned = $this->get($original)->assertOk()->viewData('orders')->pluck('id')->all();
        $this->assertSame([$other->id], $returned);
    }

    public function test_return_to_disappeared_page_redirects_to_last_page_without_clearing_filters(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $last = $this->order();
        }
        $last->update(['status' => 'shipped']);
        $query = ['status' => 'new', 'source' => 'allegro', 'page' => 2, 'per_page' => 20, 'list_return' => 1];
        $this->get(route('orders.index', $query))->assertRedirect(route('orders.index', array_replace($query, ['page' => 1])));
        $this->get(route('orders.index', array_replace($query, ['page' => 1])))->assertOk()
            ->assertViewHas('hasActiveFilters', true)
            ->assertViewHas('filters', fn ($filters) => $filters['source'] === 'allegro');
    }

    public function test_return_to_empty_results_preserves_filters_on_first_page(): void
    {
        $query = ['status' => 'new', 'source' => 'allegro', 'page' => 3, 'per_page' => 50, 'list_return' => 1];
        $this->get(route('orders.index', $query))->assertRedirect(route('orders.index', array_replace($query, ['page' => 1])));
        $this->get(route('orders.index', array_replace($query, ['page' => 1])))->assertOk()
            ->assertViewHas('hasActiveFilters', true)
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 0);
    }

    public function test_return_to_tracking_search_does_not_redirect_back_to_the_order(): void
    {
        $order = $this->order();
        $order->shipments()->create([
            'provider' => 'inpost_courier', 'service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
            'tracking_number' => 'TRACK-123', 'status' => Shipment::STATUS_CONFIRMED, 'request_uuid' => (string) Str::uuid(),
        ]);
        $redirect = $this->get(route('orders.index', ['q' => 'TRACK-123']))->assertRedirect();
        $details = $this->get($redirect->headers->get('Location'))->assertOk();
        $this->get($details->viewData('ordersListUrl'))->assertOk()
            ->assertViewHas('searchQuery', 'TRACK-123')
            ->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$order->id]);
    }

    public function test_sidebar_all_and_clear_filters_keep_their_distinct_meanings(): void
    {
        $order = $this->order();
        $context = ['status' => 'all', 'source' => 'allegro', 'page' => 1, 'per_page' => 20];
        $details = $this->get($this->orderUrl($order, $context))->assertOk();
        $all = $this->xpath($details->getContent())->query('//nav[@id="orderStatusNavigation"]/a[contains(@class,"orders-context-link-all")]')->item(0);
        $this->assertSame($details->viewData('ordersListUrl'), $all?->getAttribute('href'));
        $list = $this->get($details->viewData('ordersListUrl'))->assertOk();
        $clear = $this->xpath($list->getContent())->query('//form[contains(@class,"orders-filters-panel")]//a[contains(@class,"orders-filter-action-button")]')->item(0);
        $this->assertNotNull($clear);
        $cleared = $this->get($clear->getAttribute('href'))->assertOk()->assertViewHas('hasActiveFilters', false);
        $this->assertArrayNotHasKey('source', $cleared->viewData('orderListContext'));
    }

    public function test_saved_view_remains_selected_after_returning_from_an_order(): void
    {
        $order = $this->order();
        $saved = OrderSavedView::query()->create(['name' => 'Test', 'sort_order' => 1, 'filters' => ['status' => 'new', 'source' => 'allegro']]);
        $context = ['status' => 'new', 'source' => 'allegro', 'page' => 1, 'per_page' => 20];
        $details = $this->get($this->orderUrl($order, $context))->assertOk();
        $this->get($details->viewData('ordersListUrl'))->assertOk()->assertViewHas('activeSavedViewId', $saved->id);
    }

    public function test_malformed_context_cannot_inject_redirect_targets_or_html(): void
    {
        $order = $this->order();
        $response = $this->get(route('orders.show', ['order' => $order, 'list' => [
            'status' => ['invalid'], 'page' => -10, 'per_page' => 7, 'source' => ['invalid'],
            'return_to' => 'https://evil.test', 'notes' => '<script>test</script>',
        ]]))->assertOk();
        $this->assertSame(route('orders.index', ['notes' => '<script>test</script>', 'page' => 1, 'per_page' => 20, 'list_return' => 1]), $response->viewData('ordersListUrl'));
        $response->assertDontSee('href="https://evil.test', false)->assertDontSee('<script>test</script>', false);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    private function orderUrl(Order $order, array $context): string
    {
        return route('orders.show', OrderListNavigationContext::orderParameters($order->id, $context));
    }

    private function order(string $status = 'new', string $createdAt = '2026-10-15 11:00:00'): Order
    {
        $order = Order::query()->create([
            'source' => 'allegro', 'status' => $status, 'currency' => 'PLN',
            'total_gross' => '100.00', 'paid_amount' => '0.00',
        ]);
        $order->timestamps = false;
        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $order->refresh();
    }
}
