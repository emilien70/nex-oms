<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReturn;
use DOMDocument;
use DOMNodeList;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderReturnIndicatorTest extends TestCase
{
    use RefreshDatabase;

    private const LABEL = 'Utworzono zwrot do zamówienia';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_order_without_a_return_does_not_show_the_icon(): void
    {
        $order = $this->order();

        $response = $this->get(route('orders.index'))->assertOk();

        $this->assertCount(0, $this->icons($response, $order));
        $response->assertDontSee('title="'.self::LABEL.'"', false);
    }

    #[DataProvider('sources')]
    public function test_linked_return_adds_one_orange_icon_only_to_its_order(string $source): void
    {
        $order = $this->order($source);
        $other = $this->order($source);
        $this->createReturn($order);

        $response = $this->get(route('orders.index'))->assertOk();

        $icons = $this->icons($response, $order);
        $this->assertCount(1, $icons);
        $this->assertCount(0, $this->icons($response, $other));
        $icon = $icons->item(0);
        $this->assertSame(self::LABEL, $icon->getAttribute('title'));
        $this->assertSame(self::LABEL, $icon->getAttribute('aria-label'));
        $this->assertSame('0', $icon->getAttribute('tabindex'));
        $this->assertSame('bi bi-arrow-return-left', $icon->getElementsByTagName('i')->item(0)->getAttribute('class'));
        $response->assertSee('.order-indicator.return { background: #eb840f; }', false)
            ->assertSee('aria-label="Płatność"', false)
            ->assertSee('aria-label="Przesyłka"', false);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function sources(): array
    {
        return [['manual'], ['allegro'], ['prestashop'], ['von_halsky'], ['other_shop']];
    }

    public function test_multiple_closed_or_rejected_returns_still_show_only_one_icon(): void
    {
        $order = $this->order();
        $this->createReturn($order)->update(['status' => 'FINISHED']);
        $this->createReturn($order)->update(['status' => 'REJECTED']);

        $this->assertCount(1, $this->icons($this->get(route('orders.index'))->assertOk(), $order));
    }

    public function test_unlinked_return_does_not_match_an_order_by_snapshot(): void
    {
        $order = $this->order();
        $this->createReturn()->update(['order_snapshot' => ['order_id' => $order->id]]);

        $this->assertCount(0, $this->icons($this->get(route('orders.index'))->assertOk(), $order));
    }

    public function test_list_signature_changes_on_creation_and_removal_without_touching_the_order(): void
    {
        $order = $this->order();
        $original = $order->fresh()->getRawOriginal();
        $before = $this->getJson(route('orders.list-state'))->assertOk()->json('signature');

        $record = $this->createReturn($order);
        $after = $this->getJson(route('orders.list-state'))->assertOk()->json('signature');

        $this->assertNotSame($before, $after);
        $this->assertSame($original, $order->fresh()->getRawOriginal());
        $record->delete();
        $this->assertSame($before, $this->getJson(route('orders.list-state'))->assertOk()->json('signature'));
        $this->assertCount(0, $this->icons($this->get(route('orders.index'))->assertOk(), $order));
    }

    public function test_replacing_a_return_in_the_same_second_still_refreshes_the_list(): void
    {
        $this->freezeTime();
        $first = $this->order();
        $second = $this->order();
        $record = $this->createReturn($first);
        $before = $this->getJson(route('orders.list-state'))->assertOk()->json('signature');

        $record->delete();
        $this->createReturn($second);

        $this->assertNotSame($before, $this->getJson(route('orders.list-state'))->assertOk()->json('signature'));
    }

    public function test_returns_outside_the_selected_status_do_not_refresh_the_filtered_list(): void
    {
        $visible = $this->order();
        $hidden = $this->order();
        $hidden->update(['status' => Order::STATUS_PENDING]);
        $url = route('orders.list-state', ['status' => Order::STATUS_NEW]);
        $before = $this->getJson($url)->assertOk()->json('signature');

        $this->createReturn($hidden);

        $this->assertSame($before, $this->getJson($url)->assertOk()->json('signature'));
        $this->createReturn($visible);
        $this->assertNotSame($before, $this->getJson($url)->assertOk()->json('signature'));
    }

    public function test_return_queries_do_not_grow_with_the_number_of_orders(): void
    {
        $this->createReturn($this->order());
        $queries = [];
        $recording = false;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$recording): void {
            if ($recording && str_contains($query->sql, 'order_returns')) {
                $queries[] = $query->sql;
            }
        });
        $recording = true;
        $this->get(route('orders.index'))->assertOk();
        $recording = false;
        $singleOrderQueries = count($queries);
        $this->assertGreaterThan(0, $singleOrderQueries);
        for ($i = 0; $i < 10; $i++) {
            $this->createReturn($this->order());
        }

        $queries = [];
        $recording = true;
        $this->get(route('orders.index'))->assertOk();
        $recording = false;

        $this->assertCount($singleOrderQueries, $queries);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function order(string $source = 'manual'): Order
    {
        return Order::query()->create([
            'source' => $source, 'status' => Order::STATUS_NEW, 'currency' => 'PLN',
            'total_gross' => '10.00', 'paid_amount' => '0.00', 'payment_status' => 'unpaid',
        ]);
    }

    private function createReturn(?Order $order = null): OrderReturn
    {
        return OrderReturn::query()->create([
            'order_id' => $order?->id, 'source' => $order?->source ?? 'manual',
            'currency' => 'PLN', 'total_value' => '10.00', 'submitted_at' => now(),
        ]);
    }

    private function icons(TestResponse $response, Order $order): DOMNodeList
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);

        return $xpath->query('//tr[td/input[@data-order-checkbox and @value="'.$order->id.'"]]'
            .'//span[contains(concat(" ", normalize-space(@class), " "), " order-indicator ")]'
            .'[contains(concat(" ", normalize-space(@class), " "), " return ")]');
    }
}
