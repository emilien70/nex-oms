<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAjaxUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_state_can_render_order_fragments(): void
    {
        $order = $this->order();
        $order->items()->create([
            'product_name' => 'Produkt AJAX',
            'quantity' => 2,
            'unit_price_gross' => 25,
            'total_price_gross' => 50,
            'currency' => 'PLN',
        ]);
        $order->events()->create([
            'event_type' => 'test_event',
            'title' => 'Zdarzenie AJAX',
        ]);

        $response = $this->getJson(route('orders.state', $order).'?fragments=order-info,shipping,billing,pickup,products,history,shipments');

        $response->assertOk()->assertJsonStructure([
            'fields',
            'latest_event_id',
            'shipments_signature',
            'fragments' => [
                'order-info',
                'shipping',
                'billing',
                'pickup',
                'products',
                'shipments',
                'history',
            ],
        ]);

        $this->assertStringContainsString('Produkt AJAX', $response->json('fragments.products'));
        $this->assertStringContainsString('Zdarzenie AJAX', $response->json('fragments.history'));
        $this->assertStringContainsString('data-edit-field="shipping_company_name"', $response->json('fragments.shipping'));
        $this->assertStringContainsString('data-edit-field="billing_tax_id"', $response->json('fragments.billing'));
        $this->assertStringContainsString('data-edit-field="pickup_point_id"', $response->json('fragments.pickup'));
        $this->assertStringContainsString('nex-empty">...', $response->json('fragments.shipping'));
        $this->assertStringContainsString('nex-empty">...', $response->json('fragments.billing'));
        $this->assertStringNotContainsString('nex-empty">-', $response->json('fragments.shipping'));
        $this->assertStringNotContainsString('nex-empty">-', $response->json('fragments.billing'));
    }

    public function test_inline_order_section_returns_ajax_refresh_instructions(): void
    {
        $order = $this->order();
        $order->items()->create([
            'product_name' => 'Produkt z dostawa',
            'quantity' => 2,
            'unit_price_gross' => 50,
            'total_price_gross' => 100,
            'currency' => 'PLN',
        ]);

        $this->patchJson(route('orders.sections.order-info', $order), [
            'source' => 'manual',
            'customer_login' => 'ajax-user',
            'customer_email' => 'ajax@example.test',
            'customer_phone' => '501294368',
            'shipping_method' => 'Paczkomat',
            'cash_on_delivery' => false,
            'delivery_cost_gross' => '12.50',
            'payment_method' => 'Przelew',
            'notes' => 'Zapis bez przeładowania',
        ])
            ->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('refresh.0', 'order-info')
            ->assertJsonPath('refresh.1', 'history');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_login' => 'ajax-user',
            'shipping_method' => 'Paczkomat',
            'total_gross' => '112.50',
        ]);
    }

    public function test_order_page_maps_inline_rows_to_their_editor_fields(): void
    {
        $response = $this->get(route('orders.show', $this->order()))->assertOk();

        foreach ([
            'customer_login',
            'customer_email',
            'customer_phone',
            'source',
            'shipping_method',
            'cash_on_delivery',
            'delivery_cost_gross',
            'payment_method',
            'notes',
            'shipping_name',
            'shipping_company_name',
            'shipping_address_line',
            'shipping_postal_code',
            'shipping_city',
            'shipping_country_code',
            'billing_name',
            'billing_company_name',
            'billing_address_line',
            'billing_postal_code',
            'billing_city',
            'billing_country_code',
            'billing_tax_id',
            'pickup_point_name',
            'pickup_point_id',
            'pickup_point_address',
            'pickup_point_postal_code',
            'pickup_point_city',
        ] as $fieldName) {
            $response->assertSee('data-edit-field="'.$fieldName.'"', false);
        }

        $response
            ->assertSee('form.elements.namedItem(fieldName)', false)
            ->assertSee('input:not([type="hidden"]), select, textarea', false)
            ->assertSee('focusTarget.focus()', false)
            ->assertSee('focusTarget.select()', false);
    }

    public function test_address_and_pickup_headers_have_visible_pencil_buttons_using_existing_editors(): void
    {
        $response = $this->get(route('orders.show', $this->order()))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);

        foreach ([
            'shipping' => 'Edytuj adres dostawy',
            'billing' => 'Edytuj dane do faktury',
            'pickup' => 'Edytuj odbiór w punkcie',
        ] as $section => $label) {
            $sectionPath = '//div[@data-inline-section="'.$section.'"]';
            $buttons = $xpath->query($sectionPath.'/div[contains(@class, "nex-card-header")]//button[@data-edit-section="'.$section.'"]');
            $this->assertSame(1, $buttons->length);
            $button = $buttons->item(0);
            $this->assertSame($label, $button->getAttribute('aria-label'));
            $this->assertSame($label, $button->getAttribute('title'));
            $this->assertStringContainsString('icon-action-button', $button->getAttribute('class'));
            $this->assertStringNotContainsString('inline-section-edit-button', $button->getAttribute('class'));
            $this->assertSame(1, $xpath->query('./i[@class="bi bi-pencil"][@aria-hidden="true"]', $button)->length);
            $this->assertSame(1, $xpath->query($sectionPath.'//form[contains(@class, "inline-section-edit")][@data-order-ajax-form]')->length);
        }

        $this->assertSame(1, $xpath->query('//button[@data-copy-shipping-to-billing]')->length);
        $this->assertSame(1, $xpath->query('//button[@data-copy-billing-to-shipping]')->length);
    }

    public function test_postal_code_and_city_are_separate_edit_targets_on_page_and_ajax_refresh(): void
    {
        $values = [
            'shipping_postal_code' => '62-865', 'shipping_city' => 'Szczytniki',
            'billing_postal_code' => '01-772', 'billing_city' => 'Warszawa',
            'pickup_point_postal_code' => '26-120', 'pickup_point_city' => 'Bliżyn',
        ];
        $order = $this->order($values);
        $page = $this->get(route('orders.show', $order))->assertOk();
        $fragments = $this->getJson(route('orders.state', $order).'?fragments=shipping,billing,pickup,order-info')
            ->assertOk()->json('fragments');
        $snapshots = [$page->getContent(), implode('', $fragments)];

        foreach ($snapshots as $html) {
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($document);
            foreach ($values as $field => $value) {
                $buttons = $xpath->query('//button[@data-edit-field="'.$field.'"]');
                $this->assertSame(1, $buttons->length, $field);
                $button = $buttons->item(0);
                $this->assertSame($value, trim($button->textContent));
                $this->assertSame(1, $xpath->query('./span[@class="inline-pencil"]/i[@class="bi bi-pencil"]', $button)->length);
            }
            $this->assertSame(3, $xpath->query('//div[contains(@class, "inline-postal-city")]')->length);
            $this->assertSame(9, $xpath->query('//button[contains(@class, "inline-edit-value")]/ancestor::div[contains(@class, "order-info-row")]')->length);
        }

        $page->assertSee('color: #0077da;', false)
            ->assertSee('text-decoration: underline;', false)
            ->assertSee('.inline-field-row:not(.inline-split-row)', false)
            ->assertSee('gap: 0 8px;', false)
            ->assertSee('.inline-postal-city .inline-edit-value + .inline-edit-value .inline-pencil', false);
    }

    public function test_editable_values_escape_customer_data_and_keep_missing_postal_parts_separate(): void
    {
        $order = $this->order([
            'shipping_name' => '<script>unsafe()</script>',
            'shipping_postal_code' => null,
            'shipping_city' => '<b>Łódź</b>',
            'billing_postal_code' => '00-001',
            'billing_city' => null,
            'pickup_point_postal_code' => null,
            'pickup_point_city' => null,
            'pickup_point_id' => '0',
        ]);
        $html = $this->getJson(route('orders.state', $order).'?fragments=shipping,billing,pickup')
            ->assertOk()->json('fragments');
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.implode('', $html));
        $xpath = new \DOMXPath($document);

        foreach ([
            'shipping_name' => '<script>unsafe()</script>',
            'shipping_postal_code' => '...', 'shipping_city' => '<b>Łódź</b>',
            'billing_postal_code' => '00-001', 'billing_city' => '...',
            'pickup_point_postal_code' => '...', 'pickup_point_city' => '...',
            'pickup_point_id' => '0',
        ] as $field => $value) {
            $this->assertSame($value, trim($xpath->query('//button[@data-edit-field="'.$field.'"]')->item(0)->textContent));
        }
        $this->assertSame(0, $xpath->query('//button//script | //button//b')->length);
    }

    public function test_detail_cards_use_compact_text_row_spacing_without_shrinking_edit_controls(): void
    {
        $response = $this->get(route('orders.show', $this->order()))->assertOk();

        $response->assertSee('--order-detail-line-height: 18.5px;', false)
            ->assertSee('gap: 2px 8px;', false)
            ->assertSee('min-height: var(--order-detail-line-height);', false)
            ->assertSee('line-height: var(--order-detail-line-height);', false);
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/\.inline-field-row,\s*\.order-info-row\s*\{[^}]*min-height:\s*var\(--order-detail-line-height\);[^}]*padding:\s*0 6px;/s', $html);
        $this->assertMatchesRegularExpression('/\.management-meta-value\s*\{[^}]*margin-top:\s*0;[^}]*min-height:\s*var\(--order-detail-line-height\);/s', $html);
        $this->assertMatchesRegularExpression('/\.inline-section-edit \.row\.g-2 > \[class\*="col-"\]\s*\{[^}]*min-height:\s*27px;/s', $html);
    }

    public function test_inline_editors_use_reference_controls_and_preserve_other_open_drafts(): void
    {
        $response = $this->get(route('orders.show', $this->order()))->assertOk();
        $response->assertDontSee('Najpierw zapisz albo anuluj aktualnie edytowana sekcje.')
            ->assertDontSee('activeSection', false)
            ->assertSee('const deferredFormFields = new WeakMap();', false)
            ->assertSee('if (hasDraft && form !== savedForm)', false)
            ->assertSee('await fetchOrderState(payload.refresh || [], true, form);', false)
            ->assertSee('deferredOrderInfoHtml = String(html ||', false);

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        foreach (['order-info', 'shipping', 'billing', 'pickup'] as $section) {
            $buttons = $xpath->query('//div[@data-inline-section="'.$section.'"]//div[@class="inline-actions"]/button');
            $this->assertSame(2, $buttons->length);
            $this->assertSame('Zapisz', trim($buttons->item(0)->textContent));
            $this->assertSame('submit', $buttons->item(0)->getAttribute('type'));
            $this->assertSame('btn inline-save', $buttons->item(0)->getAttribute('class'));
            $this->assertSame('Anuluj', trim($buttons->item(1)->textContent));
            $this->assertSame('button', $buttons->item(1)->getAttribute('type'));
            $this->assertSame('btn inline-cancel', $buttons->item(1)->getAttribute('class'));
        }

        $this->assertMatchesRegularExpression('/\.inline-section-edit \.form-label\s*\{[^}]*font-size:\s*12px;[^}]*letter-spacing:\s*0;[^}]*text-transform:\s*none;/s', $response->getContent());
        $this->assertMatchesRegularExpression('/\.inline-section-edit \.row\.g-2 > \[class\*="col-"\]\s*\{[^}]*margin-top:\s*0;/s', $response->getContent());
        $this->assertMatchesRegularExpression('/\.inline-actions\s*\{[^}]*gap:\s*9px;[^}]*justify-content:\s*flex-start;[^}]*margin-top:\s*24px;/s', $response->getContent());
    }

    public function test_order_header_uses_matching_light_text_and_preserves_star_state(): void
    {
        foreach (['' => 'bi-star', 'blue' => 'bi-star-fill'] as $color => $icon) {
            $order = $this->order(['shipping_name' => 'Marcin Powała', 'star_color' => $color ?: null]);
            $attributes = $order->fresh()->getAttributes();
            $response = $this->get(route('orders.show', $order))->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $xpath = new \DOMXPath($document);
            $heading = '//div[@class="order-heading-text"]';
            $star = '//button[contains(concat(" ", normalize-space(@class), " "), " order-star ")]';

            $this->assertSame('Zamówienie '.$order->id, trim($xpath->query($heading.'/h1')->item(0)->textContent));
            $this->assertSame('Marcin Powała', trim($xpath->query($heading.'/span[@data-order-header-customer]')->item(0)->textContent));
            $this->assertSame('bi '.$icon, $xpath->query($star.'/i[@data-order-star-icon]')->item(0)->getAttribute('class'));
            $this->assertStringContainsString($color ? 'star-blue' : 'star-empty', $xpath->query($star)->item(0)->getAttribute('class'));
            $this->assertMatchesRegularExpression('/\.order-title,\s*\.order-customer\s*\{[^}]*color:\s*#4e565f;[^}]*font-size:\s*20px;[^}]*font-weight:\s*300;/s', $response->getContent());
            $this->assertSame($attributes, $order->fresh()->getAttributes());
        }
    }

    public function test_order_header_keeps_login_fallback_and_hides_missing_customer_name(): void
    {
        foreach (['buyer-login', null] as $login) {
            $order = $this->order(['shipping_name' => null, 'customer_login' => $login]);
            $response = $this->get(route('orders.show', $order))->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $customer = (new \DOMXPath($document))->query('//span[@data-order-header-customer]')->item(0);

            $this->assertSame($login ?? '', trim($customer->textContent));
            $this->assertSame($login === null, str_contains($customer->getAttribute('class'), 'd-none'));
        }
    }

    public function test_products_table_uses_reference_headers_and_keeps_original_product_name(): void
    {
        $order = $this->order();
        $item = $order->items()->create([
            'product_name' => 'Zgemma H11s + adapter Wi-Fi',
            'quantity' => 1,
            'unit_price_gross' => '208.00',
            'total_price_gross' => '208.00',
            'currency' => 'PLN',
            'vat_rate' => '23.00',
            'weight' => '0.900',
        ]);
        $item->created_at = '2026-10-19 18:46:00';
        $item->save();
        $originalAttributes = $item->fresh()->getAttributes();

        $response = $this->get(route('orders.show', $order))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $table = '//table[contains(concat(" ", normalize-space(@class), " "), " products-table ")]';
        $headers = [];
        foreach ($xpath->query($table.'/thead/tr/th') as $header) {
            $headers[] = trim($header->textContent);
        }

        $this->assertSame(['Zdjęcie', 'ID PROD.', 'NAZWA PRODUKTU', 'ILOŚĆ', 'CENA', 'VAT', 'WAGA', 'DATA', 'AKCJE'], $headers);
        $this->assertSame(0, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " products-card ")]//h2')->length);
        $this->assertSame(1, $xpath->query($table.'//button[contains(@class, "product-actions-toggle")]')->length);
        $response->assertSee('Dodaj produkty do zam&oacute;wienia', false)
            ->assertSee('Operacje na produktach')
            ->assertSee('Zgemma H11s + adapter Wi-Fi')
            ->assertSee('208.00 PLN')
            ->assertSee('>23%</td>', false)
            ->assertSee('>0.90</td>', false)
            ->assertSee('19.10.2026<br>18:46', false);

        $fragment = $this->getJson(route('orders.state', $order).'?fragments=products')
            ->assertOk()->json('fragments.products');
        $this->assertStringContainsString('Zgemma H11s + adapter Wi-Fi', $fragment);
        $this->assertStringContainsString('208.00 PLN', $fragment);
        $this->assertStringContainsString('>23%</td>', $fragment);
        $this->assertStringContainsString('>0.90</td>', $fragment);
        $this->assertStringContainsString('19.10.2026<br>18:46', $fragment);
        $this->assertSame($originalAttributes, $item->fresh()->getAttributes());
    }

    public function test_products_table_keeps_zero_vat_precise_weight_and_missing_values(): void
    {
        $order = $this->order();
        foreach ([['vat_rate' => '0.00', 'weight' => '0.125'], ['vat_rate' => null, 'weight' => null]] as $attributes) {
            $order->items()->create(array_merge([
                'product_name' => 'Pozycja historyczna',
                'quantity' => 1,
                'unit_price_gross' => '10.01',
                'total_price_gross' => '10.01',
                'currency' => 'PLN',
            ], $attributes));
        }

        $fragment = $this->getJson(route('orders.state', $order).'?fragments=products')
            ->assertOk()->json('fragments.products');

        $this->assertStringContainsString('>0%</td>', $fragment);
        $this->assertStringContainsString('>0.125</td>', $fragment);
        $this->assertSame(2, substr_count($fragment, 'class="product-metric">-</td>'));
        $this->assertStringContainsString('10.01 PLN', $fragment);
    }

    public function test_product_controls_are_below_the_table_card_with_separate_catalog_and_menu_buttons(): void
    {
        $response = $this->get(route('orders.show', $this->order()))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $card = '//div[contains(concat(" ", normalize-space(@class), " "), " products-card ")]';
        $toolbar = $card.'/following-sibling::div[1][contains(@class, "products-toolbar")]';

        $this->assertSame(0, $xpath->query($card.'//div[contains(@class, "products-toolbar")]')->length);
        $this->assertSame(1, $xpath->query($toolbar)->length);
        $this->assertSame(1, $xpath->query($toolbar.'//button[@class="btn product-add-button"][@data-bs-toggle="modal"][@data-bs-target="#catalogProductModal"]')->length);
        $this->assertSame(1, $xpath->query($toolbar.'//button[@class="btn product-add-options"][@data-bs-toggle="dropdown"]')->length);
        $this->assertSame(1, $xpath->query($toolbar.'//button[@class="btn product-operations-button"]/i[contains(@class, "bi-wrench")]')->length);
        $this->assertSame(1, $xpath->query($toolbar.'/following-sibling::div[1][@id="productAddPanel"]//form[@data-order-ajax-form]')->length);
        $response->assertSee('Dodaj produkty do zam&oacute;wienia...', false)
            ->assertSee('Dodaj produkty z magazynu...')
            ->assertSee('Dodaj produkt ręcznie...');
    }

    public function test_payment_and_product_mutations_return_ajax_refresh_instructions(): void
    {
        $order = $this->order(['total_gross' => 100]);

        $this->patchJson(route('orders.paid-amount.update', $order), [
            'paid_amount' => 40,
        ])
            ->assertOk()
            ->assertJsonPath('refresh.0', 'order-info');

        $this->postJson(route('orders.products.store', $order), [
            'product_name' => 'Nowy produkt',
            'quantity' => 1,
            'unit_price_gross' => '49.99',
            'currency' => 'PLN',
        ])
            ->assertOk()
            ->assertJsonPath('refresh.0', 'products')
            ->assertJsonPath('refresh.1', 'order-info')
            ->assertJsonPath('refresh.2', 'history');

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_name' => 'Nowy produkt',
        ]);
        $this->assertSame('49.99', $order->fresh()->total_gross);

        $orderItem = $order->items()->firstOrFail();

        $this->patchJson(route('order-items.update', $orderItem), [
            'product_name' => 'Nowy produkt',
            'quantity' => 2,
            'unit_price_gross' => 30,
            'currency' => 'PLN',
        ])
            ->assertOk()
            ->assertJsonPath('refresh.1', 'order-info');

        $this->assertSame('60.00', $order->fresh()->total_gross);

        $this->patchJson(route('orders.paid-amount.update', $order), [
            'paid_amount' => 0,
        ])->assertOk();

        $this->deleteJson(route('order-items.destroy', $orderItem))
            ->assertOk()
            ->assertJsonPath('refresh.1', 'order-info');

        $order->refresh();
        $this->assertSame('0.00', $order->total_gross);
        $this->assertSame('0.00', $order->paid_amount);
        $this->assertSame('unpaid', $order->payment_status);

        $orderInfo = $this->getJson(route('orders.state', $order).'?fragments=order-info')
            ->assertOk()
            ->json('fragments.order-info');

        $this->assertStringContainsString('bg-secondary', $orderInfo);
        $this->assertStringContainsString('0,00 PLN', $orderInfo);
    }

    public function test_payment_actions_use_icons_and_show_payment_link_only_for_unpaid_order(): void
    {
        $unpaidOrder = $this->order([
            'total_gross' => '100.00',
            'paid_amount' => '0.00',
            'payment_status' => 'unpaid',
        ]);

        $unpaidOrderInfo = $this->getJson(route('orders.state', $unpaidOrder).'?fragments=order-info')
            ->assertOk()
            ->json('fragments.order-info');

        $this->assertStringContainsString('bi bi-arrow-clockwise', $unpaidOrderInfo);
        $this->assertStringContainsString('bi bi-pencil', $unpaidOrderInfo);
        $this->assertStringContainsString('data-payment-link-action', $unpaidOrderInfo);
        $this->assertStringContainsString('data-payment-link-url="'.route('orders.paynow.payment-link', $unpaidOrder).'"', $unpaidOrderInfo);
        $this->assertStringContainsString('Pojedyncze klikni&#281;cie: kopiuj do schowka', $unpaidOrderInfo);
        $this->assertStringContainsString('Podw&oacute;jne klikni&#281;cie: otw&oacute;rz link.', $unpaidOrderInfo);
        $this->assertStringNotContainsString('data-payment-link-placeholder', $unpaidOrderInfo);
        $this->assertStringContainsString('bi bi-link-45deg', $unpaidOrderInfo);

        $paidOrder = $this->order([
            'total_gross' => '100.00',
            'paid_amount' => '100.00',
            'payment_status' => 'paid',
        ]);

        $paidOrderInfo = $this->getJson(route('orders.state', $paidOrder).'?fragments=order-info')
            ->assertOk()
            ->json('fragments.order-info');

        $this->assertStringContainsString('bi bi-arrow-clockwise', $paidOrderInfo);
        $this->assertStringContainsString('bi bi-pencil', $paidOrderInfo);
        $this->assertStringNotContainsString('data-payment-link-action', $paidOrderInfo);
        $this->assertStringNotContainsString('bi bi-link-45deg', $paidOrderInfo);
    }

    public function test_first_eur_product_refreshes_currency_fields_fragments_and_next_product_form(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'euro', 'nbp_table' => 'A']);
        $order = $this->order([
            'currency' => 'PLN',
            'total_gross' => '0.00',
            'paid_amount' => '0.00',
            'delivery_cost_gross' => '0.00',
        ]);

        $this->postJson(route('orders.products.store', $order), [
            'product_name' => 'Produkt EUR',
            'quantity' => 1,
            'unit_price_gross' => '25.00',
            'currency' => 'EUR',
        ])
            ->assertOk()
            ->assertJsonPath('refresh.0', 'products')
            ->assertJsonPath('refresh.1', 'order-info')
            ->assertJsonPath('refresh.2', 'history');

        $state = $this->getJson(route('orders.state', $order).'?fragments=products,order-info')
            ->assertOk()
            ->assertJsonPath('fields.currency', 'EUR');

        $orderInfo = $state->json('fragments.order-info');
        $products = $state->json('fragments.products');

        $this->assertStringContainsString('0,00 EUR', $orderInfo);
        $this->assertGreaterThanOrEqual(2, substr_count($orderInfo, '0,00 EUR'));
        $this->assertStringContainsString('25,00 EUR', $orderInfo);
        $this->assertStringContainsString('Produkt EUR', $products);
        $this->assertStringContainsString('25.00 EUR', $products);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('value="EUR" selected', false)
            ->assertDontSee('value="PLN" selected', false);
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => 0,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'shipping_name' => 'Jan Kowalski',
            'shipping_street' => 'Testowa',
            'shipping_building_number' => '12',
            'shipping_postal_code' => '00-001',
            'shipping_city' => 'Warszawa',
        ], $overrides));
    }
}
