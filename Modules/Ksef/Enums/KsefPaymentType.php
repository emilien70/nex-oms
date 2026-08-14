<?php

namespace Modules\Ksef\Enums;

enum KsefPaymentType: string
{
    case Original = 'original';
    case Cash = 'cash';
    case Card = 'card';
    case Voucher = 'voucher';
    case Cheque = 'cheque';
    case Credit = 'credit';
    case Transfer = 'transfer';
    case Mobile = 'mobile';

    public function label(): string
    {
        return match ($this) {
            self::Original => 'Oryginalny opis z zamówienia',
            self::Cash => 'Gotówka',
            self::Card => 'Karta',
            self::Voucher => 'Bon',
            self::Cheque => 'Czek',
            self::Credit => 'Kredyt',
            self::Transfer => 'Przelew',
            self::Mobile => 'Mobilna',
        };
    }

    public function fa3Code(): ?string
    {
        return match ($this) {
            self::Original => null,
            self::Cash => '1',
            self::Card => '2',
            self::Voucher => '3',
            self::Cheque => '4',
            self::Credit => '5',
            self::Transfer => '6',
            self::Mobile => '7',
        };
    }
}
