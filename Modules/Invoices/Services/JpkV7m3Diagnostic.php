<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Exceptions\InvoiceDomainException;

final class JpkV7m3Diagnostic
{
    public static function from(InvoiceDomainException $exception): array
    {
        $metadata = $exception->metadata();
        $reason = $metadata['reason'] ?? 'data_invalid';

        return ['code' => $reason, 'field' => $metadata['field'] ?? 'Dane eksportu', 'message' => $exception->getMessage(),
            'detected_codes' => $metadata['detected_codes'] ?? [],
            'hint' => match ($reason) {
                'classification_missing' => 'Sprawdź historyczną klasyfikację pozycji dokumentu. Eksport nie uzupełnia jej automatycznie.',
                'pln_invalid' => 'Sprawdź zapisany kurs i historyczne podsumowanie PLN dokumentu.',
                'procedure_unsupported' => 'Ustal obsługę tej procedury w programie księgowym. Ten profil nie ma jej mapowania.',
                'vat_unsupported' => 'Zweryfikuj faktyczną stawkę i klasyfikację dokumentu z księgowością.',
                'formal_gtu_unresolved' => 'Ustal oznaczenia formalnej Korekty z księgowością; ten profil ich nie rozstrzyga.',
                'ksef_unresolved' => 'Wyjaśnij lokalny stan i powiązanie KSeF dokumentu. Ręczne oznaczenie nie zastępuje sprzecznych dowodów.',
                'ksef_confirmation_required' => 'Wybierz właściwe oznaczenie BFK, OFF albo DI dla tego dokumentu i wygeneruj plik ponownie.',
                'xsd_invalid' => 'Sprawdź wskazane pole, jego format i długość. Pobranie wymaga zgodności z przypiętym XSD.',
                default => 'Sprawdź wskazane dane w dokumencie lub formularzu. Dane historyczne nie są naprawiane przez eksport.',
            }];
    }
}
