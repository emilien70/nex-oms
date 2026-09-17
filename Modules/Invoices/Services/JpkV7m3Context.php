<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Ksef\Services\KsefFa3BuyerIdentityResolver;

final class JpkV7m3Context
{
    public const TAXPAYER_FIELDS = ['jpk_type', 'jpk_nip', 'jpk_name', 'jpk_first_name', 'jpk_last_name', 'jpk_birth_date', 'jpk_email', 'jpk_phone', 'jpk_office'];

    public function __construct(public readonly array $data)
    {
        $year = filter_var($data['jpk_year'] ?? null, FILTER_VALIDATE_INT);
        $month = filter_var($data['jpk_month'] ?? null, FILTER_VALIDATE_INT);
        if ($year === false || $year < 2026 || $year > 2090 || $month === false || $month < 1 || $month > 12 || ($year === 2026 && $month === 1)) {
            self::fail(0, 'okres JPK', 'Wybierz jawny miesiąc od lutego 2026 do grudnia 2090.');
        }
        self::taxpayerData($data);
        if (! in_array($data['jpk_purpose'] ?? null, ['1', '2'], true)) {
            self::fail(0, 'Podmiot1/CelZlozenia', 'Wybierz prawidłowy typ podatnika i cel pliku.');
        }
    }

    /** Shared validation for an export and the separately saved profile. */
    public static function taxpayerData(array $data): array
    {
        $labels = ['jpk_type' => 'Typ podatnika', 'jpk_nip' => 'NIP podatnika', 'jpk_office' => 'Urząd skarbowy',
            'jpk_email' => 'E-mail', 'jpk_name' => 'Pełna nazwa', 'jpk_first_name' => 'Pierwsze imię',
            'jpk_last_name' => 'Nazwisko', 'jpk_birth_date' => 'Data urodzenia', 'jpk_phone' => 'Telefon'];
        if (! in_array($data['jpk_type'] ?? null, ['person', 'organization'], true)) {
            self::fail(0, 'Typ podatnika', 'Wybierz osobę fizyczną / JDG albo osobę niefizyczną.', 'taxpayer_invalid');
        }
        $active = ['jpk_type', 'jpk_nip', 'jpk_office', 'jpk_email', ...($data['jpk_type'] === 'person'
            ? ['jpk_first_name', 'jpk_last_name', 'jpk_birth_date'] : ['jpk_name'])];
        foreach ($active as $field) {
            self::text($data[$field] ?? null, 0, $labels[$field], true);
        }
        if (! preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/D', $data['jpk_nip'])
            || (new KsefFa3BuyerIdentityResolver)->normalizePolishNip($data['jpk_nip']) !== $data['jpk_nip']) {
            self::fail(0, 'NIP podatnika', 'Podaj poprawny NIP bez prefiksu i separatorów.');
        }
        if (! app(JpkTaxOfficeCatalog::class)->contains($data['jpk_office'])) {
            self::fail(0, 'Urząd skarbowy', 'Kod nie występuje w słowniku przypiętego schematu. Wybierz urząd ponownie.', 'office_unknown');
        }
        if (filter_var($data['jpk_email'], FILTER_VALIDATE_EMAIL) === false) {
            self::fail(0, 'E-mail', 'Podaj poprawny adres e-mail podatnika.', 'taxpayer_invalid');
        }
        if ($data['jpk_type'] === 'person' && (app(SalesRegisterValues::class)->date($data['jpk_birth_date']) === null
            || $data['jpk_birth_date'] < '1900-01-01' || $data['jpk_birth_date'] > '2100-12-31')) {
            self::fail(0, 'DataUrodzenia', 'Podaj prawidłową datę urodzenia podatnika.');
        }
        self::text($data['jpk_phone'] ?? '', 0, 'Telefon');
        foreach (['jpk_name' => 240, 'jpk_first_name' => 30, 'jpk_last_name' => 81, 'jpk_email' => 255, 'jpk_phone' => 16] as $field => $limit) {
            if (($field === 'jpk_phone' || in_array($field, $active, true)) && mb_strlen($data[$field] ?? '') > $limit) {
                self::fail(0, $labels[$field], 'Maksymalna długość to '.$limit.' znaków.', 'taxpayer_invalid');
            }
        }
        $result = array_fill_keys(self::TAXPAYER_FIELDS, '');
        foreach ([...$active, 'jpk_phone'] as $field) {
            $result[$field] = $data[$field] ?? '';
        }

        return $result;
    }

    public static function text(mixed $value, int $id, string $field, bool $required = false): string
    {
        if ($value === null && ! $required) {
            return '';
        }
        if (! is_string($value) || ($required && trim($value) === '') || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $value)) {
            self::fail($id, $field, 'Brak poprawnego tekstu UTF-8/XML 1.0.');
        }

        return $value;
    }

    public static function fail(int $id, string $field, string $message, string $reason = 'data_invalid', array $details = []): never
    {
        if ($reason === 'data_invalid' && in_array($field, ['KSeF', 'KSeF offline'], true)) {
            $reason = 'ksef_unresolved';
        }
        throw new InvoiceDomainException('sales_register_jpk_invalid', ($id ? 'Dokument ID '.$id.', ' : '').'pole '.$field.': '.$message,
            ['document_id' => $id, 'field' => $field, 'reason' => $reason] + $details);
    }
}
