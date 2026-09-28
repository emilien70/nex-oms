<?php

namespace App\Enums;

enum OrderEmailMessageSource: string
{
    case Manual = 'manual';
    case Automation = 'automation';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Ręcznie',
            self::Automation => 'Automatyczna akcja',
        };
    }
}
