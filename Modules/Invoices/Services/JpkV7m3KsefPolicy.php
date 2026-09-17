<?php

namespace Modules\Invoices\Services;

final class JpkV7m3KsefPolicy
{
    public function resolve(array $record, array $evidence, string $nip, ?string $confirmation): array
    {
        $id = $record['id'];
        foreach ($record['warnings'] as $warning) {
            if (in_array($warning['code'], ['sales_register_ksef_link_invalid', 'sales_register_ksef_number_ambiguous'], true)) {
                JpkV7m3Context::fail($id, 'KSeF', 'Niespójne powiązanie numeru produkcyjnego; potwierdzenie ręczne nie może go zastąpić.');
            }
        }
        if ($record['ksef_number'] !== null) {
            if ($confirmation !== null) {
                JpkV7m3Context::fail($id, 'KSeF', 'Nie wolno nadpisywać numeru KSeF oznaczeniem.');
            }

            return ['NrKSeF' => $record['ksef_number']];
        }
        $outside = $evidence['outside'];
        $offline = $evidence['offline'];
        $submissions = $evidence['submissions'];
        if ($outside !== [] && ($offline !== [] || $submissions !== [])) {
            JpkV7m3Context::fail($id, 'KSeF', 'Sprzeczne dane o wystawieniu i transmisji produkcyjnej.');
        }
        if ($outside !== []) {
            if (count($outside) !== 1 || $outside[0]['provenance'] !== 'outside_ksef' || $confirmation !== null) {
                JpkV7m3Context::fail($id, 'KSeF', 'Niejednoznaczne potwierdzenie wystawienia poza KSeF.');
            }

            return ['BFK' => '1'];
        }
        if ($offline !== []) {
            if (count($offline) !== 1 || $confirmation !== null) {
                JpkV7m3Context::fail($id, 'KSeF', 'Niejednoznaczne wystawienie offline.');
            }
            $issued = $offline[0];
            $hash = base64_decode((string) $issued['invoice_hash'], true);
            if ($issued['seller_nip'] !== $nip || $issued['context_identifier_type'] !== 'Nip' || $issued['context_identifier_value'] !== $nip
                || substr($issued['issue_date'], 0, 10) !== $record['issue_date'] || $hash === false || strlen($hash) !== 32
                || base64_encode($hash) !== $issued['invoice_hash'] || $issued['invoice_size'] < 1 || ! $issued['schema_id']) {
                JpkV7m3Context::fail($id, 'KSeF offline', 'Niespójna lokalna tożsamość wystawienia offline.');
            }
            foreach ($submissions as $submission) {
                if ($submission['offline_issuance_id'] !== $issued['id'] || $submission['status'] === 'accepted') {
                    JpkV7m3Context::fail($id, 'KSeF offline', 'Niespójna transmisja lub brak wiarygodnego numeru zaakceptowanej faktury.');
                }
            }

            return match ($issued['procedure']) {
                'failure' => ['OFF' => '1'],
                'offline24', 'planned_unavailability' => ['DI' => '1'],
                default => JpkV7m3Context::fail($id, 'KSeF offline', 'Nieobsługiwany tryb wystawienia.'),
            };
        }
        if ($submissions !== []) {
            JpkV7m3Context::fail($id, 'KSeF', 'Transmisja produkcyjna nie ma wiarygodnego numeru ani potwierdzonego trybu offline. Najpierw wyjaśnij jej stan.');
        }
        if ($confirmation === null) {
            return [];
        }
        if (! in_array($confirmation, ['BFK', 'OFF', 'DI'], true)) {
            JpkV7m3Context::fail($id, 'KSeF', 'Nieprawidłowe potwierdzenie sposobu wystawienia.');
        }

        return [$confirmation => '1'];
    }
}
