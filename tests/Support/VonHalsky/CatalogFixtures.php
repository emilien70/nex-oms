<?php

namespace Tests\Support\VonHalsky;

use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyProductLink;
use Modules\Products\Models\Product;

trait CatalogFixtures
{
    private function account(array $attributes = []): VonHalskyAccount
    {
        return VonHalskyAccount::query()->create(array_replace([
            'name' => 'multi-click', 'environment' => 'production',
            'organization_id' => 'd8ec11ce-12ba-4fb7-be9a-249e679f4e67', 'client_id' => 'TEST_CLIENT',
            'client_secret' => 'TEST_SECRET', 'access_token' => 'TEST_TOKEN', 'token_expires_at' => now()->addMinutes(5),
            'is_active' => true, 'last_test_status' => 'success',
        ], $attributes))->refresh();
    }

    private function product(array $attributes = []): Product
    {
        return Product::query()->create(array_replace(['name' => 'Nazwa OMS', 'price_gross' => '999.99',
            'currency' => 'PLN', 'vat_rate' => '8.00', 'stock_quantity' => 10, 'is_active' => true], $attributes))->refresh();
    }

    private function link(VonHalskyAccount $account, Product $product, string $offer = 'offer-test'): VonHalskyProductLink
    {
        return VonHalskyProductLink::query()->create(['product_id' => $product->id, 'von_halsky_account_id' => $account->id,
            'organization_id' => $account->organization_id, 'environment' => $account->environment->value, 'external_offer_id' => $offer]);
    }

    private function sourceOrder(): array
    {
        return ['id' => 'PG76P11', 'organizationId' => 'd8ec11ce-12ba-4fb7-be9a-249e679f4e67',
            'status' => 'ACCEPTED', 'createdAt' => '2026-10-28T09:17:51.841Z',
            'customer' => ['firstName' => 'Jan', 'lastName' => 'Testowy', 'email' => 'test@example.invalid', 'phoneNumber' => '+48500500500',
                'address' => ['street' => 'Kwiatowa', 'building' => '1', 'postCode' => '00-001', 'city' => 'Warszawa', 'countryCode' => 'PL']],
            'invoice' => null, 'comment' => null,
            'delivery' => ['deliveryType' => 'APM', 'deliveryPoint' => 'APM_TEST', 'price' => ['amount' => 0, 'currency' => 'PLN']],
            'orderLines' => [['offer' => ['offerId' => 'offer-test', 'product' => ['name' => 'Nazwa Von Halsky'],
                'price' => ['amount' => 195, 'currency' => 'PLN'], 'finalPrice' => ['amount' => 195, 'currency' => 'PLN']]]],
            'finalPrice' => ['amount' => 195, 'currency' => 'PLN'],
            'paymentDetails' => ['selectedPaymentType' => 'BLIK_CODE', 'payments' => [
                ['paymentId' => 'PAYMENT_TEST', 'paymentType' => 'BLIK_CODE', 'paymentDate' => '2026-10-28T09:18:30.017Z'],
            ]]];
    }
}
