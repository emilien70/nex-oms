<?php

namespace Tests\Support;

class AllegroReturnFixture
{
    public const ID = '39e10c74-92e6-42a7-bad7-c4c4d21ec011';

    public static function payload(): array
    {
        return [
            'id' => self::ID, 'orderId' => AllegroOrderFixture::ORDER_ID,
            'createdAt' => '2026-10-21T10:00:00Z', 'referenceNumber' => 'RET-TEST-2026',
            'buyer' => ['login' => 'kupujacy-test', 'email' => 'buyer@example.test'],
            'items' => [[
                'offerId' => '12345678901', 'name' => 'Produkt zwracany', 'quantity' => 2,
                'price' => ['amount' => '30.01', 'currency' => 'PLN'],
                'reason' => ['type' => 'MISTAKE', 'userComment' => 'Komentarz kupujacego'],
            ]],
            'parcels' => [], 'status' => 'CREATED', 'marketplaceId' => 'allegro-pl',
            'refund' => ['bankAccount' => ['accountNumber' => 'PRIVATE-BANK-ACCOUNT']],
        ];
    }
}
