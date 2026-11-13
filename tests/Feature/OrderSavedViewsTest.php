<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderSavedView;
use App\Models\OrderStatusSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderSavedViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_list_displays_saved_filter_controls_and_views(): void
    {
        $view = OrderSavedView::query()->create([
            'name' => 'Zamowienia pilne',
            'filters' => ['status' => Order::STATUS_PENDING, 'customer' => 'Anna'],
            'sort_order' => 1,
        ]);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertSee('Zapisane filtry')
            ->assertSee('Stw&oacute;rz nowy widok', false)
            ->assertSee('Zarz&#261;dzanie widokami', false)
            ->assertSee($view->name)
            ->assertSee(route('orders.saved-views.update', $view), false);
    }

    public function test_saved_view_stores_normalized_filters_and_applies_them_to_orders(): void
    {
        $matching = $this->order('Anna Kowalska', Order::STATUS_PENDING);
        $other = $this->order('Jan Nowak', Order::STATUS_PENDING);

        $response = $this->postJson(route('orders.saved-views.store'), [
            'name' => 'Anna oczekujace',
            'filters' => [
                'customer' => '  Anna  ',
                'status' => Order::STATUS_PENDING,
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('view.filters.customer', 'Anna')
            ->assertJsonPath('view.filters.status', Order::STATUS_PENDING);

        $view = OrderSavedView::query()->sole();
        $this->assertSame([
            'status' => Order::STATUS_PENDING,
            'customer' => 'Anna',
        ], $view->filters);

        $this->get(route('orders.index', $view->filters))
            ->assertOk()
            ->assertSee(route('orders.show', $matching), false)
            ->assertDontSee(route('orders.show', $other), false);
    }

    public function test_saved_view_can_be_renamed_and_overwritten_with_an_empty_filter_set(): void
    {
        $view = OrderSavedView::query()->create([
            'name' => 'Stary widok',
            'filters' => ['product' => 'Pilot'],
            'sort_order' => 1,
        ]);

        $this->patchJson(route('orders.saved-views.update', $view), [
            'name' => 'Wszystkie',
            'replace_filters' => true,
            'filters' => [],
        ])
            ->assertOk()
            ->assertJsonPath('view.name', 'Wszystkie')
            ->assertJsonPath('view.filters', []);

        $view->refresh();
        $this->assertSame('Wszystkie', $view->name);
        $this->assertSame([], $view->filters);
    }

    public function test_saved_product_filter_is_applied_in_the_current_status_without_changing_the_template(): void
    {
        $new = $this->order('Anna', Order::STATUS_NEW);
        $shipped = $this->order('Anna', Order::STATUS_SHIPPED);
        $other = $this->order('Anna', Order::STATUS_NEW);
        foreach ([[$new, 'Zgemma H8.2H'], [$shipped, 'Zgemma H8.2H'], [$other, 'Pilot']] as [$order, $product]) {
            $order->items()->create([
                'product_name' => $product,
                'quantity' => 1,
                'unit_price_gross' => '10.00',
                'total_price_gross' => '10.00',
                'currency' => 'PLN',
            ]);
        }

        OrderStatusSetting::syncDefaults();
        $scope = 'status:'.OrderStatusSetting::query()->where('status', Order::STATUS_NEW)->sole()->id;
        foreach ([
            ['product' => 'H8.2H'],
            ['q' => 'inna fraza', 'status' => Order::STATUS_SHIPPED, 'product' => 'H8.2H'],
        ] as $filters) {
            $view = OrderSavedView::query()->create([
                'name' => 'H8.2H',
                'filters' => $filters,
                'sort_order' => 1,
            ]);

            $target = route('orders.index', ['product' => 'H8.2H', 'status' => Order::STATUS_NEW, 'per_page' => 30]);
            $this->get(route('orders.index', ['status' => Order::STATUS_NEW, 'per_page' => 30, 'page' => 2]))
                ->assertOk()
                ->assertSee('href="'.e($target).'"', false)
                ->assertSee('data-order-filter-apply="'.$scope.'"', false);
            $this->get($target)->assertOk()
                ->assertViewHas('currentStatus', Order::STATUS_NEW)
                ->assertViewHas('activeSavedViewId', $view->id)
                ->assertViewHas('orders', fn ($orders): bool => $orders->currentPage() === 1 && $orders->pluck('id')->all() === [$new->id]);

            $this->assertSame($filters, $view->fresh()->filters);
            $view->delete();
        }
    }

    public function test_saved_view_preserves_the_current_search_or_trash_context(): void
    {
        $view = OrderSavedView::query()->create([
            'name' => 'H8.2H',
            'filters' => ['q' => 'old search', 'status' => Order::STATUS_SHIPPED, 'product' => 'H8.2H', 'sort' => 'total', 'direction' => 'asc'],
            'sort_order' => 1,
        ]);
        $criteria = ['product' => 'H8.2H', 'sort' => 'total', 'direction' => 'asc'];

        $this->get(route('orders.index', ['q' => 'current search']))->assertOk()
            ->assertViewHas('savedViewParameters', fn ($sets): bool => $sets[$view->id] === $criteria + ['q' => 'current search'])
            ->assertDontSee('data-order-filter-apply=', false);
        $this->get(route('orders.index', ['trash' => 1]))->assertOk()
            ->assertViewHas('savedViewParameters', fn ($sets): bool => $sets[$view->id] === $criteria + ['trash' => 1])
            ->assertSee('data-order-filter-apply="trash"', false);
    }

    public function test_saved_views_can_be_reordered_and_deleted(): void
    {
        $first = $this->savedView('Pierwszy', 1);
        $second = $this->savedView('Drugi', 2);
        $third = $this->savedView('Trzeci', 3);

        $this->patchJson(route('orders.saved-views.order'), [
            'views' => [$third->id, $first->id, $second->id],
        ])->assertOk()->assertJsonPath('saved', true);

        $this->assertSame(
            [$third->id, $first->id, $second->id],
            OrderSavedView::query()->ordered()->pluck('id')->all()
        );

        $this->deleteJson(route('orders.saved-views.destroy', $first))
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('order_saved_views', ['id' => $first->id]);
        $this->assertSame(
            [1, 2],
            OrderSavedView::query()->ordered()->pluck('sort_order')->all()
        );
    }

    public function test_saved_view_rejects_unknown_state_and_an_incomplete_order(): void
    {
        $first = $this->savedView('Pierwszy', 1);
        $this->savedView('Drugi', 2);

        $this->postJson(route('orders.saved-views.store'), [
            'name' => 'Niepoprawny',
            'filters' => [
                'customer' => 'Anna',
                'per_page' => '500',
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('filters');

        $this->patchJson(route('orders.saved-views.order'), [
            'views' => [$first->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('views');
    }

    private function order(string $shippingName, string $status): Order
    {
        return Order::query()->create([
            'source' => 'manual',
            'status' => $status,
            'shipping_name' => $shippingName,
            'currency' => 'PLN',
        ]);
    }

    private function savedView(string $name, int $sortOrder): OrderSavedView
    {
        return OrderSavedView::query()->create([
            'name' => $name,
            'filters' => [],
            'sort_order' => $sortOrder,
        ]);
    }
}
