<?php

namespace Tests\Support;

class AllegroOrderFixture
{
    public const ORDER_ID = 'c6287a22-57b5-41ea-93bf-4dbbe06503ca';

    public const PAYMENT_ID = '2dae8362-57b6-41ea-97c2-b71e91a5aa42';

    public static function payload(): array
    {
        return [
            'id' => self::ORDER_ID,
            'status' => 'READY_FOR_PROCESSING',
            'revision' => 'revision-1',
            'updatedAt' => '2026-10-19T10:01:00Z',
            'marketplace' => ['id' => 'allegro-pl'],
            'fulfillment' => ['status' => 'NEW', 'provider' => ['id' => 'SELLER']],
            'messageToSeller' => 'Proszę o staranne zapakowanie.',
            'buyer' => [
                'id' => '111222333', 'login' => 'test-kupujacy',
                'email' => 'masked+full-suffix@allegromail.pl', 'phoneNumber' => '+48 600 100 200',
                'address' => ['street' => 'Nie używaj 1', 'city' => 'Inne miasto', 'postCode' => '00-000', 'countryCode' => 'PL'],
            ],
            'delivery' => [
                'address' => [
                    'firstName' => 'Jan', 'lastName' => 'Testowy', 'companyName' => 'Odbiorca',
                    'street' => 'Dostawcza 10/2', 'city' => 'Kraków', 'zipCode' => '30-001',
                    'countryCode' => 'PL', 'phoneNumber' => '+48 510 005 298',
                ],
                'method' => ['id' => '2488f7b7-5d1c-4d65-b85c-4cbcf253fd93', 'name' => 'Allegro Paczkomaty InPost'],
                'pickupPoint' => ['id' => 'JDC02M', 'name' => 'Paczkomat JDC02M', 'address' => ['street' => 'Polna 4', 'zipCode' => '38-460', 'city' => 'Jedlicze']],
                'cost' => ['amount' => '10.00', 'currency' => 'PLN'],
                'smart' => true, 'calculatedNumberOfPackages' => 1,
            ],
            'invoice' => [
                'required' => true,
                'address' => [
                    'street' => 'Fakturowa 20', 'city' => 'Warszawa', 'zipCode' => '00-001', 'countryCode' => 'PL',
                    'company' => ['name' => 'Firma Testowa', 'taxId' => '1234567890'],
                    'naturalPerson' => null,
                ],
            ],
            'payment' => [
                'id' => self::PAYMENT_ID, 'type' => 'ONLINE', 'provider' => 'PAYU',
                'finishedAt' => '2026-10-19T10:01:00Z',
                'paidAmount' => ['amount' => '100.02', 'currency' => 'PLN'], 'reconciliation' => null,
            ],
            'lineItems' => [[
                'id' => 'ffc36fa0-9584-41e8-8d53-07c966f77738',
                'offer' => ['id' => '12345678901', 'name' => 'Produkt Allegro'],
                'quantity' => 3,
                'originalPrice' => ['amount' => '35.00', 'currency' => 'PLN'],
                'price' => ['amount' => '30.01', 'currency' => 'PLN'],
                'boughtAt' => '2026-10-19T10:00:00Z',
                'selectedAdditionalServices' => [], 'reconciliation' => null,
            ]],
            'summary' => ['totalToPay' => ['amount' => '100.03', 'currency' => 'PLN']],
            'surcharges' => [[
                'id' => '45b636fa-9584-41e8-8d53-07c966f77738',
                'type' => 'ONLINE', 'provider' => 'PAYU', 'finishedAt' => '2026-10-19T10:02:00Z',
                'paidAmount' => ['amount' => '0.01', 'currency' => 'PLN'],
            ]],
        ];
    }
}
