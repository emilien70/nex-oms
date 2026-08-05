<?php

namespace Modules\Invoices\Enums;

enum CorrectionReason: string
{
    case AdvanceRefund = 'advance_refund';
    case MandatoryDiscount = 'mandatory_discount';
    case PriceIncrease = 'price_increase';
    case UndueAmountRefund = 'undue_amount_refund';
    case GoodsReturn = 'goods_return';
    case InvoiceError = 'invoice_error';
    case BuyerDataUpdate = 'buyer_data_update';
    case Withdrawal = 'withdrawal';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AdvanceRefund => 'zwrot nabywcy zaliczek, przedpłat, zadatków lub rat',
            self::MandatoryDiscount => 'obowiązkowe rabaty (bonifikaty, upusty)',
            self::PriceIncrease => 'podwyższenie ceny po wystawieniu faktury',
            self::UndueAmountRefund => 'zwrot nabywcy kwot nienależnych',
            self::GoodsReturn => 'zwrot sprzedawcy towarów',
            self::InvoiceError => 'pomyłki co do ceny, stawki, kwoty podatku lub pozycji faktury',
            self::BuyerDataUpdate => 'aktualizacja danych nabywcy',
            self::Withdrawal => 'odstąpienie od umowy',
            self::Other => 'inny powód - uzupełnij w komentarzu',
        };
    }
}
