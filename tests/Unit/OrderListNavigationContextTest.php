<?php

namespace Tests\Unit;

use App\Support\OrderListNavigationContext;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderListNavigationContextTest extends TestCase
{
    private const STATUSES = ['new' => 'Nowe', 'pending' => 'Oczekujace', 'custom' => 'Wlasny'];

    public function test_it_preserves_filters_pagination_and_dynamic_statuses(): void
    {
        $context = OrderListNavigationContext::fromList(Request::create('/orders', 'GET', [
            'status' => 'custom', 'source' => 'prestashop', 'payment' => 'unpaid',
            'customer' => ' Anna ', 'cash_on_delivery' => '0', 'page' => '3', 'per_page' => '50',
        ]), self::STATUSES);
        $this->assertSame([
            'status' => 'custom', 'source' => 'prestashop', 'customer' => 'Anna',
            'cash_on_delivery' => '0', 'payment' => 'unpaid', 'page' => 3, 'per_page' => 50,
        ], $context);
        $this->assertSame(['order' => 123, 'status' => 'custom', 'list' => $context], OrderListNavigationContext::orderParameters(123, $context));
        $this->assertSame($context + ['list_return' => 1], OrderListNavigationContext::listParameters($context));
    }

    public function test_search_keeps_its_existing_all_statuses_behavior(): void
    {
        $context = OrderListNavigationContext::fromList(Request::create('/orders', 'GET', [
            'status' => 'pending', 'trash' => '1', 'q' => ' m_tomek ',
        ]), self::STATUSES);
        $this->assertSame(['q' => 'm_tomek', 'status' => 'all', 'page' => 1, 'per_page' => 20], $context);
        $this->assertArrayNotHasKey('status', OrderListNavigationContext::listParameters($context));
        $this->assertArrayNotHasKey('trash', $context);
    }

    public function test_only_whitelisted_scalar_fields_are_carried_into_navigation(): void
    {
        $context = OrderListNavigationContext::fromOrder(Request::create('/orders/1', 'GET', ['list' => [
            'status' => ['new'], 'source' => ['allegro'], 'q' => ['private'], 'page' => ['3'],
            'per_page' => 999, 'notes' => 'test', 'return_to' => 'https://evil.test/',
            'redirect' => '//evil.test', 'list_return' => 0, 'order' => 999,
        ]]), self::STATUSES);
        $this->assertSame(['notes' => 'test', 'status' => 'all', 'page' => 1, 'per_page' => 20], $context);
        $this->assertSame(['notes' => 'test', 'page' => 1, 'per_page' => 20, 'list_return' => 1], OrderListNavigationContext::listParameters($context));
    }

    #[DataProvider('invalidPages')]
    public function test_invalid_page_values_use_first_page(mixed $page): void
    {
        $context = OrderListNavigationContext::fromList(Request::create('/orders', 'GET', ['page' => $page]), self::STATUSES);
        $this->assertSame(1, $context['page']);
    }

    public static function invalidPages(): array
    {
        return [[-1], [0], ['1.5'], ['x'], ['9999999999999999999999'], [1000001], [[]]];
    }

    public function test_missing_or_malformed_context_preserves_legacy_navigation(): void
    {
        $this->assertNull(OrderListNavigationContext::fromOrder(Request::create('/orders/1'), self::STATUSES));
        $this->assertNull(OrderListNavigationContext::fromOrder(Request::create('/orders/1', 'GET', ['list' => 'https://evil.test']), self::STATUSES));
    }

    public function test_contexts_from_two_tabs_do_not_overwrite_each_other(): void
    {
        $first = Request::create('/orders/1', 'GET', ['list' => ['status' => 'new', 'source' => 'allegro']]);
        $second = Request::create('/orders/2', 'GET', ['list' => ['status' => 'pending', 'source' => 'prestashop']]);
        $firstContext = OrderListNavigationContext::fromOrder($first, self::STATUSES);
        $this->assertSame('prestashop', OrderListNavigationContext::fromOrder($second, self::STATUSES)['source']);
        $this->assertSame($firstContext, OrderListNavigationContext::fromOrder($first, self::STATUSES));
    }
}
