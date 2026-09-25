<?php

namespace App\Enums;

enum EmailTemplateAttachmentType: string
{
    case None = 'none';
    case Upload = 'upload';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Brak załącznika',
            self::Upload => 'Wybierz plik z dysku',
            self::Document => 'Załącz fakturę/dokument, plik lub wybrany wydruk',
        };
    }
}
