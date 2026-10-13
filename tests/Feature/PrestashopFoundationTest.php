<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Modules\Integrations\Prestashop\Models\PrestashopOrderPayment;
use Modules\Integrations\Prestashop\Models\PrestashopOrderState;
use Tests\TestCase;

class PrestashopFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_and_models_store_an_encrypted_key_and_discovered_order_states(): void
    {
        $this->assertTrue(Schema::hasColumns('prestashop_accounts', [
            'account_key',
            'base_url',
            'product_url_template',
            'api_key',
            'shop_id',
            'language_id',
            'available_currencies',
            'order_enrichment_resource',
            'last_test_status',
        ]));
        $this->assertTrue(Schema::hasColumns('prestashop_order_states', [
            'prestashop_account_id',
            'external_id',
            'name',
            'paid',
            'shipped',
            'delivery',
            'refreshed_at',
        ]));

        $account = PrestashopAccount::query()->create([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => false,
        ]);
        $state = $account->orderStates()->create([
            'external_id' => 2,
            'name' => 'Płatność zaakceptowana',
            'color' => '#32CD32',
            'paid' => true,
            'refreshed_at' => now(),
        ]);

        $this->assertNotSame(
            '12345678901234567890123456789012',
            DB::table('prestashop_accounts')->value('api_key'),
        );
        $this->assertArrayNotHasKey('api_key', $account->toArray());
        $this->assertTrue($state->paid);
        $this->assertSame($account->getKey(), $state->account->getKey());
    }

    public function test_discovered_states_are_removed_with_their_integration_account(): void
    {
        $account = PrestashopAccount::query()->create([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => false,
        ]);
        $state = $account->orderStates()->create([
            'external_id' => 1,
            'name' => 'Oczekiwanie na płatność',
            'refreshed_at' => now(),
        ]);

        $account->delete();

        $this->assertDatabaseMissing('prestashop_order_states', ['id' => $state->getKey()]);
        $this->assertNull(PrestashopOrderState::query()->find($state->getKey()));
    }

    public function test_import_schema_keeps_external_product_and_payment_identifiers_separate(): void
    {
        $this->assertTrue(Schema::hasColumns('prestashop_order_links', [
            'prestashop_account_id',
            'order_id',
            'external_order_id',
            'external_reference',
            'external_order_state_id',
            'source_hash',
            'imported_at',
        ]));
        $this->assertTrue(Schema::hasColumns('prestashop_order_item_links', [
            'prestashop_order_link_id',
            'order_item_id',
            'external_order_detail_id',
            'external_product_id',
            'external_product_attribute_id',
        ]));
        $this->assertTrue(Schema::hasColumns('prestashop_order_payments', [
            'prestashop_order_link_id',
            'external_payment_id',
            'transaction_id',
            'amount',
            'currency',
            'method',
            'paid_at',
        ]));
        $this->assertFalse(Schema::hasColumn('prestashop_order_payments', 'card_number'));
        $this->assertFalse(Schema::hasColumn('prestashop_order_payments', 'card_holder'));

        $account = $this->account();
        $order = Order::query()->create([
            'source' => 'prestashop',
            'external_id' => '13581',
            'status' => 'new',
            'currency' => 'PLN',
        ]);
        $link = PrestashopOrderLink::query()->create([
            'prestashop_account_id' => $account->getKey(),
            'order_id' => $order->getKey(),
            'external_order_id' => 13581,
            'external_reference' => '13581',
            'source_hash' => str_repeat('a', 64),
            'imported_at' => now(),
        ]);
        $payment = $link->payments()->create([
            'external_payment_id' => 9121,
            'transaction_id' => 'payment-1',
            'amount' => '462.00',
            'currency' => 'PLN',
            'method' => 'InPost Pay',
        ]);

        $this->assertSame($order->getKey(), $link->order->getKey());
        $this->assertSame($account->getKey(), $link->account->getKey());
        $this->assertSame('462.00', $payment->amount);
        $this->assertInstanceOf(PrestashopOrderPayment::class, $link->payments->first());
    }

    private function account(): PrestashopAccount
    {
        return PrestashopAccount::query()->create([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => true,
        ]);
    }
}
