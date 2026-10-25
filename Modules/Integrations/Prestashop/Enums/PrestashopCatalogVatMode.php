<?php

namespace Modules\Integrations\Prestashop\Enums;

enum PrestashopCatalogVatMode: string
{
    case Store = 'store';
    case Fallback = 'fallback';
    case Catalog = 'catalog';

    public function label(): string
    {
        return match ($this) {
            self::Store => 'Nie - używaj stawki VAT podanej przez sklep',
            self::Fallback => 'Tak - używaj stawki OMS, jeśli sklep nie podał żadnej stawki',
            self::Catalog => 'Tak - używaj stawki OMS, a przy jej braku stawki ze sklepu',
        };
    }
}
