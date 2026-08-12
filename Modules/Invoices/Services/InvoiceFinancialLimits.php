<?php

namespace Modules\Invoices\Services;

final class InvoiceFinancialLimits
{
    /** @var array{precision: int, scale: int, signed: bool} */
    public const ORDER_MONEY = ['precision' => 12, 'scale' => 2, 'signed' => false];

    /** @var array{precision: int, scale: int, signed: bool} */
    public const INVOICE_ITEM_QUANTITY = ['precision' => 15, 'scale' => 4, 'signed' => false];

    /** @var array{precision: int, scale: int, signed: bool} */
    public const INVOICE_ITEM_UNIT_PRICE = ['precision' => 15, 'scale' => 4, 'signed' => false];

    /** @var array{precision: int, scale: int, signed: bool} */
    public const INVOICE_ITEM_TOTAL = ['precision' => 15, 'scale' => 2, 'signed' => false];

    /** @var array{precision: int, scale: int, signed: bool} */
    public const INVOICE_DOCUMENT_TOTAL = ['precision' => 15, 'scale' => 2, 'signed' => false];

    /** @var array{precision: int, scale: int, signed: bool} */
    public const CORRECTION_DIFFERENCE = ['precision' => 15, 'scale' => 2, 'signed' => true];

    /** @var array{precision: int, scale: int, signed: bool} */
    public const CORRECTION_QUANTITY_DIFFERENCE = ['precision' => 15, 'scale' => 4, 'signed' => true];

    /** @var array{precision: int, scale: int, signed: bool} */
    public const CORRECTION_UNIT_PRICE_DIFFERENCE = ['precision' => 15, 'scale' => 4, 'signed' => true];

    /** @var array{precision: int, scale: int, signed: bool} */
    public const VAT_STORAGE = ['precision' => 5, 'scale' => 2, 'signed' => false];

    public const ORDER_QUANTITY_MAX = 2_147_483_647;

    private function __construct() {}
}
