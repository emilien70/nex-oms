<?php

namespace Modules\Automation\Services;

final class AutomationCustomEventAppearance
{
    public const DEFAULT_ICON = 'list-check';

    public const DEFAULT_COLOR = '#B3B3B3';

    public const COLORS = [
        '#003366', '#669999', '#339933', '#006600', '#003300', '#333300', '#999966',
        '#663300', '#FF9900', '#FF3399', '#CC3300', '#990000', '#993333', '#660033',
        '#990099', '#660066', '#9900FF', '#666699', '#333399', '#3333FF', '#0033CC',
        '#003399', '#0066CC', '#000066', '#FFFFFF', '#B3B3B3', '#404040', '#0D0D0D',
    ];

    public const ICONS = [
        'flag-fill' => 'Flaga', 'star-fill' => 'Gwiazdka', 'cash' => 'Banknot', 'currency-dollar' => 'Dolar', 'currency-euro' => 'Euro', 'truck' => 'Przesyłka',
        'chat-dots' => 'Rozmowa', 'envelope' => 'Koperta', 'phone-fill' => 'Telefon', 'reply-fill' => 'Odpowiedź', 'person-fill' => 'Użytkownik', 'person-standing' => 'Osoba',
        'bell' => 'Dzwonek', 'clock' => 'Zegar', 'pencil-fill' => 'Ołówek', 'tags-fill' => 'Znaczniki', 'trash-fill' => 'Kosz', 'gear-fill' => 'Ustawienia',
        'printer-fill' => 'Drukarka', 'filetype-pdf' => 'Plik PDF', 'file-earmark-image' => 'Obraz', 'file-earmark-text' => 'Dokument', 'file-earmark' => 'Plik', 'files' => 'Kopie',
        'hand-thumbs-up' => 'Kciuk w górę', 'hand-thumbs-down' => 'Kciuk w dół', 'link' => 'Powiązanie', 'emoji-smile' => 'Uśmiech', 'emoji-neutral' => 'Neutralny', 'emoji-frown' => 'Smutny',
        'check-lg' => 'Zatwierdzenie', 'x-lg' => 'Krzyżyk', 'asterisk' => 'Gwiazdka tekstowa', 'arrow-up-circle' => 'W górę', 'arrow-down-circle' => 'W dół', 'slash-circle' => 'Zakaz',
        'cart-fill' => 'Koszyk', 'list-check' => 'Lista zadań', 'sliders' => 'Suwaki', 'speedometer' => 'Licznik', 'bar-chart-steps' => 'Wykres', 'diagram-3-fill' => 'Schemat',
    ];
}
