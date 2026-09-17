<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Ksef\Services\KsefFa3BuyerIdentityResolver;

final class JpkV7m3Context
{
    public function __construct(public readonly array $data)
    {
        $year = filter_var($data['jpk_year'] ?? null, FILTER_VALIDATE_INT);
        $month = filter_var($data['jpk_month'] ?? null, FILTER_VALIDATE_INT);
        if ($year === false || $year < 2026 || $year > 2090 || $month === false || $month < 1 || $month > 12 || ($year === 2026 && $month === 1)) {
            self::fail(0, 'okres JPK', 'Wybierz jawny miesiąc od lutego 2026 do grudnia 2090.');
        }
        foreach (['jpk_nip', 'jpk_office', 'jpk_email', 'jpk_type', 'jpk_purpose'] as $field) {
            self::text($data[$field] ?? null, 0, $field, true);
        }
        if (! in_array($data['jpk_type'], ['person', 'organization'], true)
            || ! in_array($data['jpk_purpose'], ['1', '2'], true)) {
            self::fail(0, 'Podmiot1/CelZlozenia', 'Wybierz prawidłowy typ podatnika i cel pliku.');
        }
        if ((new KsefFa3BuyerIdentityResolver)->normalizePolishNip($data['jpk_nip']) !== $data['jpk_nip']) {
            self::fail(0, 'NIP podatnika', 'Podaj poprawny NIP bez prefiksu i separatorów.');
        }
        if (! preg_match('/^\d{4}$/D', $data['jpk_office']) || filter_var($data['jpk_email'], FILTER_VALIDATE_EMAIL) === false) {
            self::fail(0, 'KodUrzedu/Email', 'Podaj kod urzędu i poprawny adres e-mail.');
        }
        foreach ($data['jpk_type'] === 'person' ? ['jpk_first_name', 'jpk_last_name', 'jpk_birth_date'] : ['jpk_name'] as $field) {
            self::text($data[$field] ?? null, 0, $field, true);
        }
        if ($data['jpk_type'] === 'person' && app(SalesRegisterValues::class)->date($data['jpk_birth_date']) === null) {
            self::fail(0, 'DataUrodzenia', 'Podaj prawidłową datę urodzenia podatnika.');
        }
        self::text($data['jpk_phone'] ?? '', 0, 'Telefon');
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

    public static function fail(int $id, string $field, string $message): never
    {
        throw new InvoiceDomainException('sales_register_jpk_invalid', ($id ? 'Dokument ID '.$id.', ' : '').'pole '.$field.': '.$message);
    }
}
