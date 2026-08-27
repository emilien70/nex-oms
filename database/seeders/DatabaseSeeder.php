<?php

namespace Database\Seeders;

use App\Models\Order;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $orders = [
            [
                'external_id' => 'DEMO-ORDER-NEW',
                'source' => 'manual',
                'status' => Order::STATUS_NEW,
                'status_changed_at' => now()->subDays(1),
                'total_gross' => 249.98,
                'paid_amount' => 249.98,
                'delivery_cost_gross' => 14.99,
                'payment_status' => 'paid',
                'payment_method' => 'PayU',
                'purchased_at' => now()->subDays(1),
                'paid_at' => now()->subDays(1)->addMinutes(4),
                'notes' => 'Testowe zamowienie w statusie nowe.',
            ],
            [
                'external_id' => 'DEMO-ORDER-PENDING',
                'source' => 'manual',
                'status' => Order::STATUS_PENDING,
                'status_changed_at' => now()->subHours(5),
                'total_gross' => 399.00,
                'paid_amount' => 399.00,
                'delivery_cost_gross' => 0.00,
                'payment_status' => 'paid',
                'payment_method' => 'Przelew tradycyjny',
                'purchased_at' => now()->subHours(6),
                'paid_at' => now()->subHours(5),
                'notes' => 'Testowe zamowienie oczekujace.',
            ],
            [
                'external_id' => 'DEMO-ORDER-SHIPPED',
                'source' => 'manual',
                'status' => Order::STATUS_SHIPPED,
                'status_changed_at' => now()->subHours(2),
                'total_gross' => 89.00,
                'paid_amount' => 89.00,
                'delivery_cost_gross' => 9.99,
                'payment_status' => 'paid',
                'payment_method' => 'PayU',
                'purchased_at' => now()->subHours(3),
                'paid_at' => now()->subHours(3)->addMinutes(3),
                'notes' => 'Testowe zamowienie wyslane.',
            ],
            [
                'external_id' => 'DEMO-ORDER-CANCELLED',
                'source' => 'manual',
                'status' => Order::STATUS_CANCELLED,
                'status_changed_at' => now()->subDays(2),
                'total_gross' => 529.00,
                'paid_amount' => 0.00,
                'delivery_cost_gross' => 0.00,
                'payment_status' => 'unpaid',
                'payment_method' => 'Karta',
                'purchased_at' => now()->subDays(2),
                'paid_at' => null,
                'notes' => 'Testowe zamowienie anulowane.',
            ],
        ];

        foreach ($orders as $orderData) {
            $order = Order::updateOrCreate(
                ['external_id' => $orderData['external_id']],
                $orderData + [
                    'customer_login' => 'anna.kowalska',
                    'customer_email' => 'anna.kowalska@example.test',
                    'customer_phone' => '+48 500 100 200',
                    'shipping_name' => 'Anna Kowalska',
                    'shipping_company_name' => 'Kowalska Handel',
                    'shipping_street' => 'Testowa',
                    'shipping_building_number' => '12',
                    'shipping_apartment_number' => '4',
                    'shipping_postal_code' => '00-001',
                    'shipping_city' => 'Warszawa',
                    'shipping_country_code' => 'PL',
                    'shipping_phone' => '+48 500 100 200',
                    'shipping_email' => 'anna.kowalska@example.test',
                    'billing_name' => 'Anna Kowalska',
                    'billing_company_name' => 'Kowalska Handel',
                    'billing_tax_id' => null,
                    'billing_street' => 'Fakturowa',
                    'billing_building_number' => '8',
                    'billing_postal_code' => '00-002',
                    'billing_city' => 'Warszawa',
                    'billing_country_code' => 'PL',
                    'billing_phone' => '+48 500 100 200',
                    'billing_email' => 'ksiegowosc@example.test',
                    'currency' => 'PLN',
                ]
            );

            $order->items()->updateOrCreate(
                ['external_id' => $orderData['external_id'].'-ITEM-1'],
                [
                    'product_name' => 'Produkt testowy',
                    'sku' => 'NEX-DEMO-001',
                    'ean' => '5900000000011',
                    'offer_id' => $orderData['external_id'].'-OFFER',
                    'quantity' => 1,
                    'unit_price_gross' => max(0, $order->total_gross - $order->delivery_cost_gross),
                    'total_price_gross' => max(0, $order->total_gross - $order->delivery_cost_gross),
                ]
            );

            $order->events()->firstOrCreate(
                [
                    'event_type' => 'created',
                    'title' => 'Zamowienie testowe utworzone',
                ],
                [
                    'description' => 'Dodano lub odswiezono testowe zamowienie z seedera.',
                    'payload' => [
                        'source' => 'seeder',
                        'status' => $order->status,
                    ],
                ]
            );
        }
    }
}
