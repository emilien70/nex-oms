<?php

namespace Tests\Feature;

use App\Events\OrderPaymentRegistered;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Integrations\Allegro\Exceptions\AllegroImportException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Models\AllegroOrderPayment;
use Modules\Integrations\Allegro\Services\AllegroOrderImportService;
use Modules\Integrations\Allegro\Services\AllegroOrderPreviewService;
use Modules\Invoices\Services\InvoiceDocumentPreparationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePaymentIdentifierResolver;
use Modules\Products\Models\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\AllegroOrderFixture as Fixture;
use Tests\TestCase;

class AllegroOrderImportTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_preview_is_read_only_and_import_saves_exact_money_and_separate_identifiers(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        Event::fake([OrderPaymentRegistered::class, OrderStatusChanged::class]);
        $preview = $this->get($this->previewUrl())
            ->assertOk()->assertSee('Importuj do OMS')->assertSee(Fixture::PAYMENT_ID)
            ->assertSee('100.03')->assertSee('Stawki VAT')->viewData('orderPreview');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('allegro_order_payments', 0);

        $this->post(route('integrations.allegro.orders.import'), $this->importPayload($preview))
            ->assertRedirect(route('orders.show', Order::query()->sole()));

        $order = Order::query()->sole();
        $this->assertSame('new', $order->status);
        $this->assertSame('allegro', $order->source);
        $this->assertSame(Fixture::ORDER_ID, $order->external_id);
        $this->assertNotSame((string) $order->id, $order->external_id);
        $this->assertSame('100.03', $order->total_gross);
        $this->assertSame('100.03', $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('10.00', $order->delivery_cost_gross);
        $this->assertSame('masked+full-suffix@allegromail.pl', $order->customer_email);
        $this->assertSame('test-kupujacy', $order->customer_login);
        $this->assertSame('+48 510 005 298', $order->customer_phone);
        $this->assertSame('Dostawcza 10/2', $order->shipping_street);
        $this->assertSame('Fakturowa 20', $order->billing_street);
        $this->assertSame('Firma Testowa', $order->billing_company_name);
        $this->assertSame('1234567890', $order->billing_tax_id);
        $this->assertSame('JDC02M', $order->pickup_point_id);
        $this->assertNull($order->notes);
        $item = $order->items()->sole();
        $this->assertSame('30.01', $item->unit_price_gross);
        $this->assertSame('90.03', $item->total_price_gross);
        $this->assertSame(3, $item->quantity);
        $this->assertSame('12345678901', $item->offer_id);
        $this->assertNull($item->product_id);
        $this->assertNull($item->vat_rate);
        $link = $order->allegroOrderLink;
        $this->assertSame($account->id, $link->allegro_account_id);
        $this->assertTrue($link->smart);
        $this->assertTrue($link->invoice_required);
        $this->assertSame('Proszę o staranne zapakowanie.', $link->buyer_message);
        $this->assertDatabaseCount('allegro_order_payments', 2);
        $this->assertSame(Fixture::PAYMENT_ID, $link->payments()->oldest('id')->first()->external_payment_id);
        $this->assertNotNull($link->payments()->first()->accounted_at);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('order_events', 1);
        Event::assertNotDispatched(OrderStatusChanged::class);
        Event::assertNotDispatched(OrderPaymentRegistered::class);
        Queue::assertNothingPushed();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), Fixture::ORDER_ID));
        $logs = DB::table('integration_api_logs')->get()->toJson();
        $this->assertStringNotContainsString('allegromail', $logs);
        $this->assertStringNotContainsString('Dostawcza', $logs);

        $this->get(route('orders.show', $order))->assertOk()->assertSee('Konto testowe Allegro')
            ->assertSee('ID płatności Allegro:')->assertSee(Fixture::PAYMENT_ID)
            ->assertDontSee('Allegro Smart!:')->assertDontSee('Żądanie faktury:')
            ->assertSee('Proszę o staranne zapakowanie.')
            ->assertSee('https://allegro.pl/oferta/-12345678901', false);
        $this->get(route('orders.index'))->assertOk()->assertSee('Konto testowe Allegro');
    }

    public function test_order_transaction_keeps_bold_neutral_link_without_integration_caption(): void
    {
        $account = $this->account();
        $account->update(['name' => 'multi-click']);
        $this->fakeOrder();
        $order = $this->import($account);
        $linkAttributes = $order->allegroOrderLink->getAttributes();

        $response = $this->get(route('orders.show', $order))->assertOk()
            ->assertSee('href="https://salescenter.allegro.com/orders/'.Fixture::ORDER_ID.'"', false)
            ->assertSee('class="allegro-transaction-link"', false)
            ->assertSee('data-allegro-transaction="'.Fixture::ORDER_ID.'"', false)
            ->assertDontSee('class="allegro-transaction-source"', false)
            ->assertDontSee('Allegro Smart!:')->assertDontSee('Żądanie faktury:');
        $this->assertMatchesRegularExpression('/\.allegro-transaction-link\s*\{[^}]*color:\s*#7a838f;[^}]*font-weight:\s*700;/s', $response->getContent());
        $this->assertMatchesRegularExpression('/\.management-status-label,\s*\.management-invoice-label,\s*\.management-meta-label\s*\{[^}]*color:\s*#7a838f;/s', $response->getContent());

        $account->delete();
        $this->get(route('orders.show', $order))->assertOk()
            ->assertDontSee('class="allegro-transaction-source"', false);
        $linkAttributes['allegro_account_id'] = null;
        $this->assertSame($linkAttributes, $order->allegroOrderLink()->sole()->getAttributes());
    }

    public function test_order_source_groups_allegro_with_current_integration_name_and_snapshot_fallback(): void
    {
        $account = $this->account();
        $account->update(['name' => 'multi-click']);
        $this->fakeOrder();
        $order = $this->import($account);
        $before = $order->getAttributes();

        $response = $this->get(route('orders.show', $order))->assertOk();
        $this->assertMatchesRegularExpression(
            '/<optgroup label="Allegro" data-allegro-source-group>\s*<option value="allegro"\s+selected>multi-click<\/option>\s*<\/optgroup>/',
            $response->getContent(),
        );

        $account->update(['name' => 'Sklep <Nowa nazwa>']);
        $response = $this->get(route('orders.show', $order))->assertOk();
        $this->assertMatchesRegularExpression(
            '/<optgroup label="Allegro" data-allegro-source-group>\s*<option value="allegro"\s+selected>Sklep &lt;Nowa nazwa&gt;<\/option>\s*<\/optgroup>/',
            $response->getContent(),
        );

        $account->delete();
        $response = $this->get(route('orders.show', $order))->assertOk();
        $this->assertMatchesRegularExpression(
            '/<optgroup label="Allegro" data-allegro-source-group>\s*<option value="allegro"\s+selected>multi-click<\/option>\s*<\/optgroup>/',
            $response->getContent(),
        );
        $this->assertSame($before, $order->fresh()->getAttributes());
        Http::assertSentCount(2);
    }

    public function test_manual_order_source_lists_configured_allegro_integration_without_changing_selection(): void
    {
        $account = $this->account();
        $account->update(['name' => 'multi-click']);
        $order = $this->createDocumentOrder();

        $response = $this->get(route('orders.show', $order))->assertOk();
        $this->assertMatchesRegularExpression(
            '/<optgroup label="Allegro" data-allegro-source-group>\s*<option value="allegro"\s*>multi-click<\/option>\s*<\/optgroup>/',
            $response->getContent(),
        );
        $this->assertMatchesRegularExpression('/<option value="manual"\s+selected>/', $response->getContent());
        $this->assertSame('manual', $order->fresh()->source);
        Http::assertNothingSent();
    }

    public function test_legacy_allegro_order_with_invalid_identifier_does_not_create_an_external_link(): void
    {
        $order = $this->createDocumentOrder(['source' => 'allegro', 'external_id' => '<script>alert(1)</script>']);

        $this->get(route('orders.show', $order))->assertOk()
            ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('data-allegro-transaction="', false)
            ->assertDontSee('data-allegro-source-group', false)
            ->assertSee('<option value="allegro" selected>Allegro</option>', false);
    }

    #[DataProvider('invoiceRequests')]
    public function test_invoice_request_notice_is_shown_only_when_requested_and_only_in_billing_card(bool $requested): void
    {
        $account = $this->account();
        $raw = Fixture::payload();
        $raw['invoice']['required'] = $requested;
        $this->fakeOrder($raw);
        $order = $this->import($account);
        $before = $order->getAttributes();

        $response = $this->get(route('orders.show', $order))->assertOk();
        if ($requested) {
            $response->assertSeeInOrder([
                'data-inline-section="billing"',
                'Dane do faktury',
                'data-invoice-request-notice',
                'Klient prosi o fakturę',
                'data-edit-field="billing_name"',
            ], false);
            $this->assertSame(1, substr_count($response->getContent(), 'data-invoice-request-notice'));
            $account->delete();
            $this->get(route('orders.show', $order))->assertOk()->assertSee('Klient prosi o fakturę');
        } else {
            $response->assertDontSee('data-invoice-request-notice', false)->assertDontSee('Klient prosi o fakturę');
        }
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame($requested, $order->allegroOrderLink()->sole()->invoice_required);

        $order->update(['source' => 'manual']);
        $this->get(route('orders.show', $order))->assertOk()->assertDontSee('data-invoice-request-notice', false);
    }

    public static function invoiceRequests(): array
    {
        return ['requested' => [true], 'not requested' => [false]];
    }

    public function test_af_payment_method_uses_full_name_but_preserves_technical_provider_code(): void
    {
        $this->account();
        $raw = Fixture::payload();
        $raw['payment']['provider'] = 'AF';
        $raw['surcharges'][0]['provider'] = 'AF';
        $this->fakeOrder($raw);

        $preview = $this->get($this->previewUrl())->assertOk()
            ->assertSee('ONLINE / Allegro Finance')->viewData('orderPreview');
        $this->assertSame('Allegro Finance', $preview['payment_method']);
        $this->post(route('integrations.allegro.orders.import'), $this->importPayload($preview))->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame('Allegro Finance', $order->getRawOriginal('payment_method'));
        $this->assertSame(['AF', 'AF'], $order->allegroOrderLink->payments()->pluck('provider')->all());
        $this->get(route('orders.show', $order))->assertOk()
            ->assertSee('value="Allegro Finance"', false)->assertSee('ONLINE / Allegro Finance');
    }

    public function test_existing_af_order_displays_full_name_without_rewriting_saved_data(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        $order = $this->import($account);
        $order->update(['payment_method' => 'AF']);
        $order = $order->fresh();
        $attributes = $order->getAttributes();

        $this->assertSame('AF', $order->getRawOriginal('payment_method'));
        $this->assertSame('Allegro Finance', $order->payment_method);
        $this->assertFalse($order->isDirty());
        $this->get(route('orders.show', $order))->assertOk()->assertSee('value="Allegro Finance"', false);
        $this->assertSame($attributes, $order->fresh()->getAttributes());
    }

    #[DataProvider('unchangedPaymentMethods')]
    public function test_other_payment_methods_are_unchanged(string $source, ?string $method): void
    {
        $order = new Order(['source' => $source, 'payment_method' => $method]);

        $this->assertSame($method, $order->payment_method);
    }

    public static function unchangedPaymentMethods(): array
    {
        return [
            'PayU' => ['allegro', 'PAYU'],
            'Przelewy24' => ['allegro', 'P24'],
            'cash on delivery' => ['allegro', 'Pobranie'],
            'manual edit' => ['allegro', 'Przelew bankowy'],
            'missing method' => ['allegro', null],
            'manual order' => ['manual', 'AF'],
            'PrestaShop order' => ['prestashop', 'AF'],
        ];
    }

    public function test_cod_is_not_marked_paid_and_billing_fallback_uses_delivery_not_buyer_address(): void
    {
        $account = $this->account();
        $raw = Fixture::payload();
        $raw['payment'] = ['id' => Fixture::PAYMENT_ID, 'type' => 'CASH_ON_DELIVERY'];
        $raw['surcharges'] = [];
        $raw['invoice'] = ['required' => false, 'address' => null];
        $this->fakeOrder($raw);
        $order = $this->import($account);
        $this->assertTrue($order->cash_on_delivery);
        $this->assertSame('Pobranie', $order->payment_method);
        $this->assertSame('0.00', $order->paid_amount);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertNull($order->paid_at);
        $this->assertNull($order->allegroOrderLink->payments()->sole()->accounted_at);
        $this->assertSame($order->shipping_street, $order->billing_street);
        $this->assertNull(app(InvoicePaymentIdentifierResolver::class)->resolve($order));
    }

    public function test_retry_preserves_manual_edits_and_does_not_call_api_or_duplicate_payment(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        $preview = $this->get($this->previewUrl())->assertOk()->viewData('orderPreview');
        $this->post(route('integrations.allegro.orders.import'), $this->importPayload($preview))->assertRedirect();
        $order = Order::query()->sole();
        $order->update(['notes' => 'NUMER SERYJNY', 'status' => 'pending', 'shipping_city' => 'Ręczna zmiana']);
        Http::fake(fn () => Http::response([], 404));
        $this->post(route('integrations.allegro.orders.import'), $this->importPayload($preview))
            ->assertRedirect(route('orders.show', $order));
        $this->assertSame($order->getAttributes(), $order->fresh()->getAttributes());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('allegro_order_payments', 2);
        $this->assertDatabaseCount('order_events', 1);
        Http::assertNothingSent();
    }

    public function test_changed_data_requires_a_new_preview_before_import(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        $preview = $this->get($this->previewUrl())->assertOk()->viewData('orderPreview');
        $raw = Fixture::payload();
        $raw['delivery']['address']['city'] = 'Nowy adres';
        $this->fakeOrder($raw);
        $this->post(route('integrations.allegro.orders.import'), $this->importPayload($preview))
            ->assertRedirect($this->previewUrl())->assertSessionHasErrors('allegro_order_import');
        $this->assertDatabaseCount('orders', 0);
        $newPreview = $this->get($this->previewUrl())->assertOk()->assertSee('Nowy adres')->viewData('orderPreview');
        $this->post(route('integrations.allegro.orders.import'), $this->importPayload($newPreview))->assertRedirect();
        $this->assertDatabaseHas('orders', ['shipping_city' => 'Nowy adres']);
    }

    public function test_import_requires_an_unexpired_preview_from_the_same_account(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        $data = ['order_identifier' => Fixture::ORDER_ID, 'preview_hash' => str_repeat('a', 64)];
        $this->post(route('integrations.allegro.orders.import'), $data)->assertSessionHasErrors('allegro_order_import');
        Http::assertNothingSent();
        $preview = $this->get($this->previewUrl())->assertOk()->viewData('orderPreview');
        $this->travel(16)->minutes();
        $this->post(route('integrations.allegro.orders.import'), $this->importPayload($preview))->assertSessionHasErrors('allegro_order_import');
        $this->travelBack();
        $account->update(['allegro_user_id' => 'different-seller']);
        $this->post(route('integrations.allegro.orders.import'), $this->importPayload($preview))->assertSessionHasErrors('allegro_order_import');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_invalid_identifiers_and_disconnected_account_never_call_allegro(): void
    {
        $this->account()->update(['is_active' => false]);
        $this->get(route('integrations.allegro.edit', ['tab' => 'orders', 'order_identifier' => '123']))
            ->assertOk()->assertSee('Wpisz prawidłowy identyfikator');
        $this->get($this->previewUrl())->assertOk()->assertSee('Najpierw połącz konto Allegro');
        $this->postJson(route('integrations.allegro.orders.import'), ['order_identifier' => '123', 'preview_hash' => 'bad'])
            ->assertUnprocessable()->assertJsonValidationErrors(['order_identifier', 'preview_hash']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('orders', 0);
    }

    #[DataProvider('blockedOrders')]
    public function test_unsupported_orders_are_preview_only(string $path, mixed $value): void
    {
        $account = $this->account();
        $raw = Fixture::payload();
        data_set($raw, $path, $value);
        $this->fakeOrder($raw);
        $preview = app(AllegroOrderPreviewService::class)->preview($account, Fixture::ORDER_ID);
        $this->assertFalse($preview['importable']);
        $this->assertNotEmpty($preview['blockers']);
        try {
            app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
            $this->fail('Import powinien być zablokowany.');
        } catch (AllegroImportException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public static function blockedOrders(): array
    {
        return [
            'incomplete' => ['status', 'FILLED_IN'],
            'cancelled' => ['status', 'CANCELLED'],
            'warehouse' => ['fulfillment.provider.id', 'ALLEGRO'],
            'unknown fulfillment' => ['fulfillment.provider.id', null],
            'overpayment' => ['payment.paidAmount.amount', '100.03'],
            'total mismatch' => ['summary.totalToPay.amount', '200.00'],
            'extended term' => ['payment.type', 'EXTENDED_TERM'],
            'split payment' => ['payment.type', 'SPLIT_PAYMENT'],
            'reconciliation' => ['lineItems.0.reconciliation', ['type' => 'WALLET']],
            'unsupported surcharge' => ['surcharges.0.type', 'EXTENDED_TERM'],
            'missing invoice address' => ['invoice.address', null],
            'missing delivery' => ['delivery.address', null],
        ];
    }

    #[DataProvider('invalidOrders')]
    public function test_malformed_payloads_do_not_write_orders(string $path, mixed $value): void
    {
        $account = $this->account();
        $raw = Fixture::payload();
        data_set($raw, $path, $value);
        $this->fakeOrder($raw);
        $this->get($this->previewUrl())->assertOk()->assertViewHas('orderPreviewError', fn ($error) => filled($error));
        $this->assertDatabaseCount('orders', 0);
    }

    public static function invalidOrders(): array
    {
        return [
            'wrong order' => ['id', Fixture::PAYMENT_ID],
            'mixed currency' => ['lineItems.0.price.currency', 'EUR'],
            'fractional cents' => ['lineItems.0.price.amount', '0.001'],
            'floating point money' => ['lineItems.0.price.amount', 30.01],
            'negative money' => ['lineItems.0.price.amount', '-1.00'],
            'overflow' => ['lineItems.0.price.amount', '99999999999999.00'],
            'bad quantity' => ['lineItems.0.quantity', 0],
            'fractional quantity' => ['lineItems.0.quantity', 1.5],
            'missing offer' => ['lineItems.0.offer.id', null],
            'unsafe offer' => ['lineItems.0.offer.id', 'javascript:alert(1)'],
            'invalid date' => ['updatedAt', 'tomorrow'],
            'invalid calendar date' => ['updatedAt', '2026-02-30T10:00:00Z'],
            'missing delivery cost' => ['delivery.cost', null],
            'missing payment id' => ['payment.id', null],
            'payment missing date' => ['payment.finishedAt', null],
            'payment missing amount' => ['payment.paidAmount', null],
            'duplicate payment id' => ['surcharges.0.id', Fixture::PAYMENT_ID],
        ];
    }

    public function test_partial_payment_and_additional_services_preserve_actual_values(): void
    {
        $account = $this->account();
        $raw = Fixture::payload();
        $raw['lineItems'][0]['selectedAdditionalServices'] = [['name' => 'Pakowanie', 'quantity' => 1, 'price' => ['amount' => '4.97', 'currency' => 'PLN']]];
        $raw['summary']['totalToPay']['amount'] = '105.00';
        $raw['surcharges'] = [];
        $this->fakeOrder($raw);
        $order = $this->import($account);
        $this->assertSame('105.00', $order->total_gross);
        $this->assertSame('100.02', $order->paid_amount);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertCount(2, $order->items);
        $this->assertSame('Pakowanie', $order->items[1]->product_name);
        $this->assertNull($order->items[1]->offer_id);
    }

    public function test_api_errors_are_shown_without_creating_any_order(): void
    {
        $this->account();
        Http::fake(['*/order/checkout-forms/*' => Http::response(['errors' => [['message' => 'Prywatna informacja: customer@example.test']]], 404)]);
        $this->get($this->previewUrl())->assertOk()->assertSee('Nie znaleziono zamówienia');
        $this->assertDatabaseCount('orders', 0);
        $this->assertStringNotContainsString('customer@example.test', DB::table('integration_api_logs')->get()->toJson());
    }

    public function test_trash_and_tombstone_prevent_reimport_and_deletions_preserve_payment_history(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        $order = $this->import($account);
        $order->delete();
        $preview = app(AllegroOrderPreviewService::class)->preview($account, Fixture::ORDER_ID);
        $this->assertTrue($preview['existing_order_trashed']);
        $this->assertFalse($preview['importable']);
        try {
            app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
            $this->fail('Nie wolno duplikować zamówienia z kosza.');
        } catch (AllegroImportException) {
            $this->assertSame(1, Order::withTrashed()->count());
        }
        $order->forceDelete();
        $this->assertNull(AllegroOrderLink::query()->sole()->order_id);
        $this->assertDatabaseCount('allegro_order_payments', 2);
        $account->delete();
        $this->assertNull(AllegroOrderLink::query()->sole()->allegro_account_id);
        $account = $this->account();
        $preview = app(AllegroOrderPreviewService::class)->preview($account, Fixture::ORDER_ID);
        $this->assertFalse($preview['importable']);
        $this->assertStringContainsString('trwale usunięty', implode(' ', $preview['blockers']));
        $this->expectException(AllegroImportException::class);
        app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
    }

    public function test_orders_list_shows_buyer_login_followed_by_current_allegro_integration_name(): void
    {
        $account = $this->account();
        $account->update(['name' => 'multi-click']);
        $this->fakeOrder();
        $order = $this->import($account);
        $attributes = $order->getAttributes();

        $this->get(route('orders.index'))->assertOk()
            ->assertSee('order-source-icon source-allegro', false)
            ->assertSee('data-order-copy-login="test-kupujacy"', false)
            ->assertSee('title="Kopiuj do schowka"', false)
            ->assertSee("trigger: 'hover',", false)
            ->assertDontSee("trigger: 'hover focus',", false)
            ->assertSee('<span>(<button type="button" class="order-login-copy"', false)
            ->assertSee('>test-kupujacy</button>) z multi-click</span>', false);

        $account->update(['name' => 'Nowa nazwa integracji']);
        $this->get(route('orders.index'))->assertOk()
            ->assertSee('>test-kupujacy</button>) z Nowa nazwa integracji</span>', false);

        $account->delete();
        $this->get(route('orders.index'))->assertOk()
            ->assertSee('>test-kupujacy</button>) z multi-click</span>', false);
        $this->assertSame($attributes, $order->fresh()->getAttributes());
    }

    public function test_orders_list_uses_dots_for_missing_allegro_buyer_login(): void
    {
        $account = $this->account();
        $account->update(['name' => 'multi-click']);
        $this->fakeOrder();
        $order = $this->import($account);
        $order->update(['customer_login' => null]);

        $this->get(route('orders.index'))->assertOk()
            ->assertSee('<span>(...) z multi-click</span>', false)
            ->assertDontSee('data-order-copy-login="', false);
    }

    public function test_copyable_allegro_login_and_integration_name_are_html_escaped(): void
    {
        $account = $this->account();
        $account->update(['name' => '<b>Sklep</b>']);
        $this->fakeOrder();
        $order = $this->import($account);
        $login = '"><img src=x onerror=alert(1)>';
        $order->update(['customer_login' => $login]);

        $this->get(route('orders.index'))->assertOk()
            ->assertSee('data-order-copy-login="'.e($login).'"', false)
            ->assertSee('>'.e($login).'</button>) z '.e($account->name).'</span>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_deleted_account_does_not_lose_duplicate_guard_or_display_name(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        $order = $this->import($account);
        $account->delete();
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Konto testowe Allegro');
        $account = $this->account();
        $again = app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, str_repeat('0', 64));
        $this->assertSame($order->id, $again->id);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_import_does_not_match_catalog_or_change_stock(): void
    {
        $account = $this->account();
        $product = Product::query()->create(['name' => 'Produkt Allegro', 'stock_quantity' => 20, 'price_gross' => '99.00', 'currency' => 'PLN', 'is_active' => true])->refresh();
        $before = $product->getAttributes();
        $this->fakeOrder();
        $order = $this->import($account);
        $this->assertNull($order->items()->sole()->product_id);
        $this->assertSame($before, $product->fresh()->getAttributes());
    }

    public function test_failure_during_saving_rolls_back_the_entire_import(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        OrderItem::creating(function (): void {
            throw new \RuntimeException('Symulowana awaria zapisu pozycji');
        });
        try {
            $this->import($account);
            $this->fail('Oczekiwano awarii zapisu.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Symulowana awaria zapisu pozycji', $exception->getMessage());
        }
        foreach (['orders', 'order_items', 'allegro_order_links', 'allegro_order_payments', 'order_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_sandbox_and_production_identity_are_separate(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        $production = $this->import($account);
        $account->update(['environment' => 'sandbox']);
        $sandbox = $this->import($account);
        $this->assertNotSame($production->id, $sandbox->id);
        $this->assertSame('https://allegro.pl.allegrosandbox.pl/oferta/-12345678901', $sandbox->allegroOrderLink->offerUrl('12345678901'));
        $this->get(route('orders.show', $sandbox))->assertOk()
            ->assertSee('href="https://salescenter.allegro.com.allegrosandbox.pl/orders/'.Fixture::ORDER_ID.'"', false);
        $this->assertNull($sandbox->allegroOrderLink->offerUrl('invalid'));
        $this->assertDatabaseCount('allegro_order_links', 2);
    }

    public function test_unlinked_legacy_order_blocks_unsafe_duplicate(): void
    {
        $account = $this->account();
        $this->createDocumentOrder(['source' => 'allegro', 'external_id' => strtoupper(Fixture::ORDER_ID)]);
        $this->fakeOrder();
        $preview = app(AllegroOrderPreviewService::class)->preview($account, Fixture::ORDER_ID);
        $this->assertFalse($preview['importable']);
        $this->assertStringContainsString('bez bezpiecznego powiązania', implode(' ', $preview['blockers']));
    }

    public function test_buyer_message_is_escaped_and_does_not_become_serial_number_notes(): void
    {
        $account = $this->account();
        $raw = Fixture::payload();
        $raw['messageToSeller'] = '<script>window.bad=true</script>';
        $this->fakeOrder($raw);
        $this->get($this->previewUrl())->assertOk()->assertSee($raw['messageToSeller'])->assertDontSee($raw['messageToSeller'], false);
        $order = $this->import($account);
        $this->get(route('orders.show', $order))->assertOk()->assertSee($raw['messageToSeller'])->assertDontSee($raw['messageToSeller'], false);
        $this->assertNull($order->notes);
    }

    public function test_invoice_snapshot_uses_oms_order_number_and_allegro_payment_ids_and_is_independent(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        $order = $this->import($account);
        $order->items()->update(['vat_rate' => '23.00']);
        $series = $this->createDocumentSeries();
        $prepared = app(InvoiceDocumentPreparationService::class)->forCreation($order, $series, $this->documentContext('2026-10-19 14:00:00'));
        $snapshot = $prepared->invoiceAttributes;
        $this->assertSame((string) $order->id, $snapshot['order_reference_snapshot']);
        $this->assertSame(Fixture::ORDER_ID, $snapshot['order_snapshot']['external_id']);
        $this->assertStringContainsString(Fixture::PAYMENT_ID, $snapshot['payment_snapshot']['payment_identifier']);
        $this->assertStringNotContainsString(Fixture::ORDER_ID, $snapshot['payment_snapshot']['payment_identifier']);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext('2026-10-19 14:00:00'));
        $invoiceSnapshot = $invoice->payment_snapshot;
        $order->update(['notes' => 'Zmiana', 'customer_login' => 'Inny']);
        AllegroOrderPayment::query()->update(['external_payment_id' => DB::raw("external_payment_id || '-changed'")]);
        $this->assertSame($snapshot, $prepared->invoiceAttributes);
        $this->assertStringNotContainsString('-changed', $snapshot['payment_snapshot']['payment_identifier']);
        $this->assertSame($invoiceSnapshot, $invoice->fresh()->payment_snapshot);
    }

    private function account(): AllegroAccount
    {
        return AllegroAccount::query()->create([
            'account_key' => 'default', 'name' => 'Konto testowe Allegro', 'environment' => 'production',
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'token', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(5), 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'allegro_user_id' => '12345678', 'allegro_login' => 'multi-click', 'marketplace_id' => 'allegro-pl',
            'is_active' => true, 'last_test_status' => 'success', 'last_tested_at' => now(),
        ]);
    }

    private function fakeOrder(?array $payload = null): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['*/order/checkout-forms/*' => Http::response($payload ?? Fixture::payload())]);
    }

    private function previewUrl(): string
    {
        return route('integrations.allegro.edit', ['tab' => 'orders', 'order_identifier' => Fixture::ORDER_ID]);
    }

    private function importPayload(array $preview): array
    {
        return ['order_identifier' => Fixture::ORDER_ID, 'preview_hash' => $preview['source_hash']];
    }

    private function import(AllegroAccount $account): Order
    {
        $preview = app(AllegroOrderPreviewService::class)->preview($account, Fixture::ORDER_ID);

        return app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
    }
}
