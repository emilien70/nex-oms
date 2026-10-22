<?php

namespace Tests\Support;

class AllegroRefundFixture
{
    public const ID = 'c4209874-dcf4-4276-9209-3806f7165fed';

    public static function payload(): array
    {
        return ['id' => self::ID, 'payment' => ['id' => AllegroOrderFixture::PAYMENT_ID],
            'order' => ['id' => AllegroOrderFixture::ORDER_ID], 'reason' => 'REFUND', 'status' => 'SUCCESS',
            'createdAt' => '2026-10-21T11:00:00.292Z', 'totalValue' => ['amount' => '70.02', 'currency' => 'PLN'],
            'lineItems' => [['id' => 'ffc36fa0-9584-41e8-8d53-07c966f77738', 'type' => 'QUANTITY', 'quantity' => 2, 'value' => null]],
            'delivery' => ['value' => ['amount' => '10.00', 'currency' => 'PLN']],
            'sellerComment' => 'Rozliczenie zwrotu'];
    }
}
