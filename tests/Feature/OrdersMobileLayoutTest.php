<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrdersMobileLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_exposes_mobile_menu_and_existing_order_creation_action(): void
    {
        $this->get(route('orders.index'))
            ->assertOk()
            ->assertSee('class="sidebar text-white offcanvas-md offcanvas-start"', false)
            ->assertSee('id="appSidebar" tabindex="-1" aria-labelledby="appSidebarLabel"', false)
            ->assertSee('data-sidebar-toggle aria-controls="appSidebar" aria-expanded="false"', false)
            ->assertSee('data-bs-dismiss="offcanvas" data-bs-target="#appSidebar"', false)
            ->assertSee('method="POST" action="'.route('orders.empty-store').'"', false)
            ->assertSee('class="orders-context-add" type="submit"', false)
            ->assertSee('data-mobile-status-toggle aria-expanded="true" aria-controls="orderStatusNavigation"', false);
    }

    public function test_mobile_status_panel_uses_configured_status_and_current_count(): void
    {
        OrderStatusSetting::query()->create([
            'status' => 'mobile_review',
            'short_name' => 'Mobile review',
            'full_name' => 'Orders awaiting review',
            'color' => '#237b48',
            'sort_order' => 90,
        ]);

        foreach (range(1, 2) as $number) {
            Order::query()->create([
                'source' => 'manual',
                'status' => 'mobile_review',
                'status_changed_at' => now(),
                'currency' => 'PLN',
            ]);
        }

        $response = $this->get(route('orders.index', ['status' => 'mobile_review']))->assertOk();
        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $document->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $xpath = new DOMXPath($document);
        $link = $xpath->query('//nav[@id="orderStatusNavigation"]/a[@href="'.route('orders.index', ['status' => 'mobile_review']).'"]')->item(0);

        $this->assertNotNull($link);
        $this->assertStringContainsString('Mobile review', $link->textContent);
        $this->assertStringContainsString('active', $link->getAttribute('class'));

        $counter = $xpath->query('.//span[contains(@class, "orders-context-count")]', $link)->item(0);

        $this->assertNotNull($counter);
        $this->assertSame('2', trim($counter->textContent));
        $this->assertStringContainsString('#237b48', $counter->getAttribute('style'));
    }

    public function test_mobile_column_hooks_preserve_products_and_dates_in_desktop_markup(): void
    {
        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'status_changed_at' => now(),
            'currency' => 'PLN',
        ]);
        $order->items()->create([
            'product_name' => 'Historical order product',
            'quantity' => 1,
            'unit_price_gross' => '100.00',
            'total_price_gross' => '100.00',
            'currency' => 'PLN',
        ]);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertSee('<th class="order-items-column">Przedmioty</th>', false)
            ->assertSee('<td class="order-items-column">', false)
            ->assertSee('Historical order product')
            ->assertSee('<td class="order-date-column">', false)
            ->assertSee('<td class="order-amount-column">', false)
            ->assertSee('data-orders-page', false);
    }

    #[DataProvider('buyerLogins')]
    public function test_buyer_login_keeps_parentheses_with_edge_characters_without_changing_the_copy_value(string $login, array $expectedEdges): void
    {
        $order = Order::query()->create([
            'source' => 'allegro',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'customer_login' => $login,
        ]);
        $before = $order->fresh()->getAttributes();

        $response = $this->get(route('orders.index'))->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $buttons = $xpath->query('//button[@data-order-copy-login]');
        $this->assertCount(1, $buttons);
        $button = $buttons->item(0);

        $this->assertSame($login, $button->getAttribute('data-order-copy-login'));
        $this->assertSame('('.$login.')', $button->textContent);
        $this->assertSame('Kopiuj login '.$login.' do schowka', $button->getAttribute('aria-label'));
        $this->assertSame($expectedEdges, array_map(
            fn ($edge) => $edge->textContent,
            iterator_to_array($xpath->query('./span[@class="order-login-edge"]', $button)),
        ));
        $this->assertSame('('.$login.')', $button->parentNode->textContent);
        $this->assertSame(0, $xpath->query('//button[@data-order-copy-login]//img')->length);
        $this->assertMatchesRegularExpression('/\.order-login-edge\s*\{\s*white-space:\s*nowrap;\s*\}/', $response->getContent());
        $this->assertSame($before, $order->fresh()->getAttributes());
    }

    public static function buyerLogins(): array
    {
        return [
            'one character' => ['a', ['(a)']],
            'zero' => ['0', ['(0)']],
            'two characters' => ['ab', ['(a', 'b)']],
            'short login' => ['m_tomek', ['(m', 'k)']],
            'long numeric login' => ['Client:147718530', ['(C', '0)']],
            'long named login' => ['przewoznyKobylin', ['(p', 'n)']],
            'very long login' => [str_repeat('X', 180), ['(X', 'X)']],
            'unicode login' => ['ŁukaszŻółć', ['(Ł', 'ć)']],
            'html input' => ['<img src=x onerror=alert(1)>', ['(<', '>)']],
        ];
    }
}
