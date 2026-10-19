<?php

namespace Tests\Feature\Analytics;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Tests\TestCase;

class BasicStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_basic_statistics_page_and_navigation_are_available(): void
    {
        $this->get(route('analytics.basic'))
            ->assertOk()
            ->assertSee('Statystyki')
            ->assertSee('Analityka')
            ->assertSee('bi-bar-chart-line-fill', false)
            ->assertSee('Ilość i wartość zamówień')
            ->assertSee('Źródła zamówień')
            ->assertSee('Rodzaje wysyłki i płatności')
            ->assertSee('Klienci')
            ->assertSee('Produkty')
            ->assertSee('analytics-summary-amount is-total', false)
            ->assertSee('analytics-summary-amount is-products', false)
            ->assertSee('analytics-summary-amount is-shipping', false)
            ->assertDontSee('Pracownicy');
    }

    public function test_orders_report_uses_requested_filters_and_excludes_deleted_orders(): void
    {
        $included = $this->order([
            'total_gross' => '129.99',
            'delivery_cost_gross' => '10.00',
            'purchased_at' => '2026-10-15 10:00:00',
        ]);
        $this->order(['total_gross' => '25.00', 'purchased_at' => '2026-09-01 10:00:00']);
        $this->order(['total_gross' => '80.00', 'currency' => 'EUR', 'purchased_at' => '2026-10-15 10:00:00']);
        $deleted = $this->order(['total_gross' => '50.00', 'purchased_at' => '2026-10-15 10:00:00']);
        $deleted->delete();

        $response = $this->get(route('analytics.basic', $this->query()));

        $response->assertOk()->assertViewHas('report', function (array $report) use ($included): bool {
            return $report['summary']['orders'] === 1
                && $report['summary']['total'] === '129,99'
                && $report['summary']['products'] === '119,99'
                && $report['summary']['shipping'] === '10,00'
                && $included->exists;
        });
    }

    public function test_prestashop_account_is_a_separate_source_filter(): void
    {
        $account = PrestashopAccount::query()->create([
            'account_key' => 'multi-click',
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
        ]);
        $prestashopOrder = $this->order(['source' => 'prestashop', 'total_gross' => '199.00']);
        $this->order(['source' => 'manual', 'total_gross' => '49.00']);
        PrestashopOrderLink::query()->create([
            'prestashop_account_id' => $account->id,
            'order_id' => $prestashopOrder->id,
            'external_order_id' => 13602,
            'external_reference' => '13602',
            'source_hash' => str_repeat('a', 64),
            'imported_at' => now(),
        ]);

        $query = $this->query([
            'type' => 'sources',
            'sources' => ['prestashop:'.$account->id],
        ]);

        $this->get(route('analytics.basic', $query))
            ->assertOk()
            ->assertSee('multi-click.pl')
            ->assertViewHas('report', function (array $report): bool {
                $panel = collect($report['panels'])->firstWhere('id', 'sources-overview');

                return $panel['chart']['labels'] === ['multi-click.pl (PrestaShop)']
                    && $panel['chart']['datasets'][0]['data'] === [1]
                    && $panel['chart']['datasets'][1]['data'] === [19900];
            });
    }

    public function test_products_report_uses_order_item_snapshots_and_quantities(): void
    {
        $order = $this->order(['total_gross' => '259.97']);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_name' => 'Dekoder testowy',
            'quantity' => 2,
            'unit_price_gross' => '99.99',
            'total_price_gross' => '199.98',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_name' => 'Adapter Wi-Fi',
            'quantity' => 1,
            'unit_price_gross' => '59.99',
            'total_price_gross' => '59.99',
        ]);

        $query = $this->query(['type' => 'products']);

        $this->get(route('analytics.basic', $query))
            ->assertOk()
            ->assertViewHas('report', function (array $report): bool {
                $panel = collect($report['panels'])->firstWhere('id', 'top-products-quantity');

                return $panel['chart']['labels'][0] === 'Dekoder testowy'
                    && $panel['chart']['datasets'][0]['data'][0] === 2;
            });
    }

    public function test_fulfillment_report_contains_shipping_and_payment_sections(): void
    {
        $this->order([
            'shipping_method' => 'Paczkomaty 24/7',
            'payment_method' => 'PayNow',
            'cash_on_delivery' => false,
            'delivery_cost_gross' => '12.50',
        ]);

        $this->get(route('analytics.basic', $this->query(['type' => 'fulfillment'])))
            ->assertOk()
            ->assertSee('Rodzaje wysyłki')
            ->assertSee('Metody płatności')
            ->assertSee('Pobrania')
            ->assertSee('Ceny wysyłek')
            ->assertViewHas('report', fn (array $report): bool => count($report['panels']) === 4);
    }

    public function test_future_custom_period_is_rejected(): void
    {
        $tomorrow = now(config('app.timezone'))->addDay();

        $this->from(route('analytics.basic'))
            ->get(route('analytics.basic', $this->query([
                'date_from' => $tomorrow->toDateString(),
                'date_to' => $tomorrow->addDay()->toDateString(),
            ])))
            ->assertRedirect(route('analytics.basic'))
            ->assertSessionHasErrors('date_from');
    }

    public function test_each_report_panel_can_be_exported_as_csv(): void
    {
        $this->order(['total_gross' => '129.99', 'delivery_cost_gross' => '10.00']);

        $response = $this->get(route('analytics.basic.csv', $this->query([
            'section' => 'orders-over-time',
        ])));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload();

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Ilość zamówień', $content);
        $this->assertStringContainsString('129,99', $content);
    }

    public function test_unknown_csv_section_returns_not_found(): void
    {
        $this->get(route('analytics.basic.csv', $this->query(['section' => 'unknown'])))
            ->assertNotFound();
    }

    private function order(array $attributes = []): Order
    {
        return Order::query()->create(array_replace([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'status_changed_at' => '2026-10-15 10:00:00',
            'currency' => 'PLN',
            'total_gross' => '100.00',
            'delivery_cost_gross' => '0.00',
            'cash_on_delivery' => false,
            'purchased_at' => '2026-10-15 10:00:00',
        ], $attributes));
    }

    private function query(array $overrides = []): array
    {
        return array_replace([
            'type' => 'orders',
            'range' => 'custom',
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-19',
            'interval' => 'day',
            'currency' => 'PLN',
            'sources_filter' => 1,
            'sources' => ['source:manual', 'source:prestashop', 'source:allegro'],
            'statuses_filter' => 1,
            'statuses' => [Order::STATUS_NEW, Order::STATUS_PENDING, Order::STATUS_SHIPPED, Order::STATUS_CANCELLED],
        ], $overrides);
    }
}
