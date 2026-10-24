<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderSourceDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    #[DataProvider('integrationSources')]
    public function test_source_shows_account_and_channel_as_plain_text_on_card_and_refresh(string $source, string $name, string $channel): void
    {
        [$order, $account] = $this->linkedOrder($source, $name);
        $before = $order->getAttributes();
        $label = $name.' ('.$channel.')';

        $response = $this->get(route('orders.show', $order))->assertOk();
        $this->assertPlainLabel($label, $response->getContent());
        $this->assertMatchesRegularExpression(
            '/<optgroup label="'.preg_quote($channel, '/').'" data-'.preg_quote($source, '/').'-source-group>\s*<option value="'.preg_quote($source, '/').'"\s+selected>'.preg_quote(e($name), '/').'<\/option>/',
            $response->getContent(),
        );
        $this->assertPlainLabel($label, $this->infoFragment($order));
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame($name, $account->fresh()->name);
        Http::assertNothingSent();
    }

    public static function integrationSources(): array
    {
        return [
            ['allegro', 'multi-click', 'Allegro'],
            ['prestashop', 'multi-click.pl', 'PrestaShop'],
            ['allegro', 'Sklep <Nowa nazwa>', 'Allegro'],
            ['prestashop', 'Sklep <Nowa nazwa>', 'PrestaShop'],
        ];
    }

    #[DataProvider('channels')]
    public function test_current_account_name_is_used_without_changing_order_data(string $source): void
    {
        [$order, $account] = $this->linkedOrder($source, 'Stara nazwa');
        $before = $order->getAttributes();
        $account->update(['name' => 'Nowa nazwa']);

        $this->assertPlainLabel('Nowa nazwa ('.($source === 'allegro' ? 'Allegro' : 'PrestaShop').')', $this->infoFragment($order));
        $this->assertSame($before, $order->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public static function channels(): array
    {
        return [['allegro'], ['prestashop']];
    }

    public function test_deleted_allegro_account_keeps_snapshot_name_and_channel(): void
    {
        [$order, $account] = $this->linkedOrder('allegro', 'multi-click');
        $account->delete();

        $this->assertPlainLabel('multi-click (Allegro)', $this->infoFragment($order));
        Http::assertNothingSent();
    }

    #[DataProvider('unlinkedSources')]
    public function test_unlinked_sources_keep_only_the_existing_channel_label(string $source, string $label): void
    {
        $order = $this->order($source);
        $fragment = $this->infoFragment($order);

        $this->assertPlainLabel($label, $fragment);
        $this->assertStringNotContainsString($label.' ('.$label.')', $fragment);
        Http::assertNothingSent();
    }

    public static function unlinkedSources(): array
    {
        return [['manual', 'Ręczne'], ['allegro', 'Allegro'], ['prestashop', 'PrestaShop']];
    }

    public function test_channel_suffix_uses_the_same_source_label_as_the_selection_list(): void
    {
        [$order] = $this->linkedOrder('allegro', 'multi-click');
        $fragment = view('orders.partials.order-info-view', [
            'order' => $order->fresh(), 'sourceOptions' => ['allegro' => 'Allegro Marketplace'],
        ])->render();

        $this->assertPlainLabel('multi-click (Allegro Marketplace)', $fragment);
        Http::assertNothingSent();
    }

    private function assertPlainLabel(string $label, string $html): void
    {
        $this->assertStringContainsString('<span class="inline-edit-text ">'.e($label).'</span>', $html);
        $this->assertStringNotContainsString('<strong>'.e($label).'</strong>', $html);
        $this->assertStringNotContainsString('<b>'.e($label).'</b>', $html);
    }

    private function infoFragment(Order $order): string
    {
        return $this->getJson(route('orders.state', $order).'?fragments=order-info')
            ->assertOk()->json('fragments.order-info');
    }

    private function order(string $source): Order
    {
        return Order::query()->create([
            'source' => $source, 'external_id' => '123', 'status' => Order::STATUS_NEW,
            'status_changed_at' => now(), 'currency' => 'PLN',
        ])->fresh();
    }

    private function linkedOrder(string $source, string $name): array
    {
        $order = $this->order($source);
        if ($source === 'allegro') {
            $account = AllegroAccount::query()->create(['account_key' => 'source-display', 'name' => $name, 'environment' => 'production']);
            $order->allegroOrderLink()->create([
                'allegro_account_id' => $account->id, 'environment' => 'production', 'seller_user_id' => '123', 'account_name' => $name,
                'external_order_id' => '23dbf490-84f9-11ee-9a14-f1f90e47a9cb', 'external_status' => 'READY_FOR_PROCESSING',
                'source_hash' => str_repeat('a', 64), 'imported_at' => now(),
            ]);
        } else {
            $account = PrestashopAccount::query()->create([
                'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY, 'name' => $name,
                'base_url' => 'https://example.test', 'api_key' => str_repeat('1', 32), 'is_active' => true,
            ]);
            $account->orderLinks()->create([
                'order_id' => $order->id, 'external_order_id' => 123, 'external_reference' => '123',
                'source_hash' => str_repeat('a', 64), 'imported_at' => now(),
            ]);
        }

        return [$order->fresh(), $account];
    }
}
