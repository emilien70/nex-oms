<?php

namespace Modules\Integrations\PayNow\Enums;

enum PayNowEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Sandbox => 'Sandbox',
            self::Production => 'Produkcja',
        };
    }

    public function baseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://api.sandbox.paynow.pl',
            self::Production => 'https://api.paynow.pl',
        };
    }
}
