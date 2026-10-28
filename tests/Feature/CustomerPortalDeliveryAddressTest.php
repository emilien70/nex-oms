<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\CustomerPortal\Services\PortalAccessService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class CustomerPortalDeliveryAddressTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
        Storage::fake('local');
        Queue::fake();
        Http::swap((new Factory)->preventStrayRequests());
        PortalSetting::query()->create(['is_enabled' => true, 'show_address' => true]);
    }

    public function test_initials_postal_code_country_and_phone_are_redacted_on_the_server_without_writes(): void
    {
        $order = $this->order();
        $before = $order->fresh()->getAttributes();
        $linkBefore = $this->link($order)->getAttributes();
        $response = $this->get($this->url($order))->assertOk();
        $data = $response->viewData('data');
        $address = $data['address'];
        $this->assertSame('M**** J****', $address->name);
        $this->assertSame('Ż**** F****', $address->company_name);
        $this->assertSame('Z**** G****', $address->street);
        $this->assertSame('Ł**** N****', $address->city);
        $this->assertSame('****', $address->building_number);
        $this->assertSame('****', $address->apartment_number);
        $this->assertSame('******', $address->postal_code);
        $this->assertSame('Polska', $address->country_name);
        $this->assertSame('+48 ******060', $address->phone);
        $this->assertNull($address->phone_url);
        $this->assertSame('Ł**** Ż****', $data['buyer']->name);
        $this->assertSame('****', $data['buyer']->tax_id);
        $this->assertSame('', $data['buyer']->phone);
        foreach (['Michał Jastrzębski', 'Żółta Firma', 'Zielona Góra', 'Łódź Nowa', '12-345',
            '17A', 'Łukasz Żółć', '1234567890', '+48 501 234 060', '+48501234060'] as $private) {
            $response->assertDontSee($private);
            $this->assertStringNotContainsString($private, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame([], $this->addressLinks($response->getContent()));
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame($linkBefore, $this->link($order)->getAttributes());
        $this->assertDatabaseCount('order_events', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_verified_delivery_shows_full_phone_and_clickable_number_without_exposing_billing_phone(): void
    {
        $order = $this->order(['billing_phone' => '+48 999 888 777']);
        $url = $this->url($order);
        $this->post($url.'/weryfikacja', ['portal_value' => 'łukasz żółć'])->assertStatus(303);
        $response = $this->get($url)->assertOk()->assertSeeText('Michał Jastrzębski')->assertSeeText('12-345 Łódź Nowa, Polska')
            ->assertSeeText('+48 501 234 060')->assertDontSee('+48 999 888 777');
        $this->assertSame(['tel:+48501234060'], $this->addressLinks($response->getContent()));
        $this->assertFalse($response->viewData('data')['masked']);
    }

    #[DataProvider('initials')]
    public function test_unicode_hyphens_and_numeric_words_are_masked_without_leaking_the_remainder(string $value, string $expected): void
    {
        $order = $this->order(['shipping_name' => $value]);
        $response = $this->get($this->url($order))->assertOk();
        $this->assertSame($expected, $response->viewData('data')['address']->name);
    }

    public static function initials(): array
    {
        return [['Łukasz Żółć-Kowalski', 'Ł**** Ż****-K****'],
            ["Z\u{0307}aneta O'Connor", "Z\u{0307}**** O****"],
            ['123 Market Street 45/67', '**** M**** S**** ****'], ['A', 'A****'], ['  ', '']];
    }

    #[DataProvider('phones')]
    public function test_delivery_phone_fallback_and_short_numbers_do_not_leak_full_data(?string $shipping, ?string $customer, string $expected): void
    {
        $order = $this->order(['shipping_phone' => $shipping, 'customer_phone' => $customer]);
        $response = $this->get($this->url($order))->assertOk();
        $this->assertSame($expected, $response->viewData('data')['address']->phone);
        $this->assertNull($response->viewData('data')['address']->phone_url);
        $this->assertSame([], $this->addressLinks($response->getContent()));
    }

    public static function phones(): array
    {
        return [['501234060', '+48 600 500 003', '+48 ******060'],
            [null, '+48 600 500 003', '+48 ******003'], ['   ', '+48 600 500 003', '+48 ******003'],
            ['not a phone', '+48 600 500 003', '+48 ******003'], [null, null, ''],
            ['+48 12', '+48 600 500 003', '****'], ['+49 151 2345678', null, '+49 ******678']];
    }

    public function test_pickup_uses_only_its_own_data_and_the_customer_phone_before_and_after_verification(): void
    {
        $order = $this->order($this->point());
        $url = $this->url($order);
        $response = $this->get($url)->assertOk();
        $address = $response->viewData('data')['address'];
        $this->assertSame('P**** W****', $address->name);
        $this->assertSame('W****', $address->point_id);
        $this->assertSame('P**** ****', $address->street);
        $this->assertSame('******', $address->postal_code);
        $this->assertSame('P****', $address->city);
        $this->assertSame('+48 ******003', $address->phone);
        $this->assertSame('', $address->country_name);
        $this->post($url.'/weryfikacja', ['portal_value' => 'KLIENT@EXAMPLE.TEST'])->assertStatus(303);
        $response = $this->get($url)->assertOk();
        $text = $this->addressText($response->getContent());
        foreach (['Paczkomat WAW77Q', 'ID punktu: WAW77Q', 'Punktowa 98', '98-765 Punktowo', '+48 600 500 003'] as $public) {
            $this->assertStringContainsString($public, $text);
        }
        foreach (['Michał Jastrzębski', 'Żółta Firma', 'Zielona Góra', 'Łódź Nowa', '17A', 'Polska', '+48 501 234 060', 'Łukasz Żółć'] as $home) {
            $this->assertStringNotContainsString($home, $text);
        }
        $this->assertSame(['tel:+48600500003'], $this->addressLinks($response->getContent()));
        $this->assertSame('Łukasz Żółć', $response->viewData('data')['buyer']->name);
    }

    #[DataProvider('pointFields')]
    public function test_partial_point_never_falls_back_to_home_address(string $field, string $value): void
    {
        PortalSetting::query()->sole()->update(['mask_personal_data' => false]);
        $order = $this->order([$field => $value]);
        $response = $this->get($this->url($order))->assertOk();
        $text = $this->addressText($response->getContent());
        $this->assertStringContainsString($value, $text);
        $this->assertStringContainsString('+48 600 500 003', $text);
        foreach (['Michał Jastrzębski', 'Żółta Firma', 'Zielona Góra', '12-345', 'Łódź Nowa', 'Polska'] as $home) {
            $this->assertStringNotContainsString($home, $text);
        }
        $address = $response->viewData('data')['address'];
        $this->assertSame('', $address->company_name);
        $this->assertSame('', $address->building_number);
        $this->assertSame('', $address->apartment_number);
    }

    #[DataProvider('pointFields')]
    public function test_point_changes_and_return_to_old_values_invalidate_verification_without_changing_the_url(string $field, string $value): void
    {
        $order = $this->order($this->point());
        $url = $this->url($order);
        $link = $this->link($order);
        $stable = array_intersect_key($link->getAttributes(), array_flip(['order_id', 'token', 'token_hash', 'binding_hash']));
        $original = $order->{$field};
        $this->post($url.'/weryfikacja', ['portal_value' => 'Łukasz Żółć'])->assertStatus(303);
        $this->get($url)->assertSeeText('Punktowa 98');
        $order->update([$field => 'new-'.$value]);
        $this->get($url)->assertOk()->assertDontSee('Punktowa 98');
        $order->update([$field => $original]);
        $this->assertSame($url, $this->url($order));
        $this->assertSame($link->generation + 2, $this->link($order)->generation);
        $this->assertSame($stable, array_intersect_key($this->link($order)->getAttributes(), $stable));
        $this->get($url)->assertOk()->assertDontSee('Punktowa 98');
        $this->post($url.'/weryfikacja', ['portal_value' => 'łukasz żółć'])->assertStatus(303);
        $this->get($url)->assertSeeText('Punktowa 98');
    }

    public static function pointFields(): array
    {
        return [['pickup_point_name', 'Only Point'], ['pickup_point_id', 'OnlyID99'], ['pickup_point_id', '0'],
            ['pickup_point_address', 'Only Street 88'], ['pickup_point_postal_code', '77-777'], ['pickup_point_city', 'Only City']];
    }

    public function test_point_identity_is_not_a_verification_secret_and_missing_customer_phone_is_not_replaced(): void
    {
        $order = $this->order($this->point() + ['customer_phone' => null]);
        $url = $this->url($order);
        foreach (['Paczkomat WAW77Q', 'WAW77Q'] as $value) {
            $this->post($url.'/weryfikacja', ['portal_value' => $value])->assertStatus(422);
        }
        $this->assertSame('', $this->get($url)->viewData('data')['address']->phone);
        $this->post($url.'/weryfikacja', ['portal_value' => 'klient@example.test'])->assertStatus(303);
        $this->assertSame([], $this->addressLinks($this->get($url)->getContent()));
    }

    #[DataProvider('sources')]
    public function test_point_selection_depends_on_its_data_not_order_source_or_shipping_method(string $source): void
    {
        PortalSetting::query()->sole()->update(['mask_personal_data' => false]);
        $order = $this->order($this->point() + ['source' => $source, 'shipping_method' => 'Home courier']);
        $response = $this->get($this->url($order))->assertOk();
        $this->assertSame('WAW77Q', $response->viewData('data')['address']->point_id);
        $this->assertStringNotContainsString('Zielona Góra', $this->addressText($response->getContent()));
    }

    public static function sources(): array
    {
        return [['manual'], ['prestashop'], ['allegro'], ['other']];
    }

    public function test_admin_preview_is_unmasked_but_still_uses_only_the_pickup_destination(): void
    {
        $order = $this->order($this->point());
        $response = $this->get(route('customer-portal.preview', $order))->assertOk();
        $this->assertFalse($response->viewData('data')['masked']);
        $this->assertSame('Paczkomat WAW77Q', $response->viewData('data')['address']->name);
        $this->assertSame(['tel:+48600500003'], $this->addressLinks($response->getContent()));
        $this->assertStringNotContainsString('Zielona Góra', $this->addressText($response->getContent()));
        Http::assertNothingSent();
    }

    public function test_whitespace_point_values_are_empty_and_hidden_address_setting_is_respected(): void
    {
        $order = $this->order(array_fill_keys(array_keys($this->point()), '  '));
        $url = $this->url($order);
        $this->assertSame('M**** J****', $this->get($url)->viewData('data')['address']->name);
        PortalSetting::query()->sole()->update(['show_address' => false]);
        $response = $this->get($url)->assertOk()->assertDontSee('Adres dostawy')->assertDontSee('+48 ******060');
        $this->assertNull($response->viewData('data')['address']);
        $this->assertNull($response->viewData('data')['buyer']);
    }

    public function test_direct_database_change_invalidates_grant_and_rollback_does_not_change_the_revision(): void
    {
        $order = $this->order($this->point());
        $url = $this->url($order);
        $this->post($url.'/weryfikacja', ['portal_value' => 'Łukasz Żółć'])->assertStatus(303);
        DB::table('orders')->where('id', $order->id)->update(['pickup_point_city' => 'Changed City']);
        $this->get($url)->assertOk()->assertDontSee('Punktowa 98');
        $this->post($url.'/weryfikacja', ['portal_value' => 'Łukasz Żółć'])->assertStatus(303);
        $before = $this->link($order)->getAttributes();
        try {
            DB::transaction(function () use ($order): void {
                $order->refresh()->update(['pickup_point_name' => 'Rolled back']);
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame($before, $this->link($order)->getAttributes());
        $this->get($url)->assertOk()->assertSeeText('Punktowa 98');
    }

    private function order(array $attributes = []): Order
    {
        return $this->createDocumentOrder($attributes + ['purchased_at' => now(), 'billing_name' => 'Łukasz Żółć',
            'shipping_name' => 'Michał Jastrzębski', 'shipping_company_name' => 'Żółta Firma',
            'shipping_street' => 'Zielona Góra', 'shipping_building_number' => '17A', 'shipping_apartment_number' => '26',
            'shipping_postal_code' => '12-345', 'shipping_city' => 'Łódź Nowa', 'shipping_phone' => '+48 501 234 060',
            'customer_phone' => '+48 600 500 003'])->fresh();
    }

    private function point(): array
    {
        return ['pickup_point_name' => 'Paczkomat WAW77Q', 'pickup_point_id' => 'WAW77Q', 'pickup_point_address' => 'Punktowa 98',
            'pickup_point_postal_code' => '98-765', 'pickup_point_city' => 'Punktowo'];
    }

    private function url(Order $order): string
    {
        return app(PortalAccessService::class)->orderLink($order)['url'];
    }

    private function link(Order $order): PortalLink
    {
        return PortalLink::query()->where('order_id', $order->id)->sole();
    }

    private function addressNode(string $html): \DOMElement
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $node = (new \DOMXPath($dom))->query('//section[h2[normalize-space(.)="Adres dostawy"]]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }

    private function addressText(string $html): string
    {
        return preg_replace('/\s+/u', ' ', $this->addressNode($html)->textContent);
    }

    private function addressLinks(string $html): array
    {
        $node = $this->addressNode($html);

        return array_map(fn ($link) => $link->getAttribute('href'), iterator_to_array((new \DOMXPath($node->ownerDocument))->query('.//a', $node)));
    }
}
