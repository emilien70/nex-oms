<?php

namespace App\Enums;

enum EmailTemplateFormat: string
{
    case PlainText = 'plain_text';
    case Html = 'html';

    public function label(): string
    {
        return match ($this) {
            self::PlainText => 'Zwykły tekst',
            self::Html => 'Kod HTML',
        };
    }
}
