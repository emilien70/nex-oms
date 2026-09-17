<?php

namespace Modules\Invoices\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\File;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\ValueObjects\SalesRegisterFilters;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class SalesRegisterXlsxExporter
{
    private const HEADERS = [
        'Lp.', 'Typ', 'Pełny numer', 'Nabywca', 'Adres', 'Kod pocztowy', 'Miasto', 'NIP',
        'Data wystawienia', 'Data sprzedaży', 'Netto', 'VAT', 'Kwota VAT', 'Brutto',
        'Sposób płatności', 'Waluta', 'Kurs waluty', 'Kraj', 'Dokument powiązany',
        'Numer KSeF', 'Data autoryzacji w KSeF',
    ];

    public function __construct(private readonly SalesRegisterHtmlPresenter $labels) {}

    public function download(array $report, SalesRegisterFilters $filters): BinaryFileResponse
    {
        $path = null;
        $book = null;
        $ready = false;
        try {
            $directory = storage_path('app/private/sales-register-exports');
            File::ensureDirectoryExists($directory, 0700);
            $candidate = $directory.DIRECTORY_SEPARATOR.'register-'.bin2hex(random_bytes(16)).'.xlsx';
            $handle = fopen($candidate, 'x+b');
            if ($handle === false) {
                throw new \RuntimeException('Temporary export file unavailable.');
            }
            $path = $candidate;
            fclose($handle);
            chmod($path, 0600);
            $book = new Spreadsheet;
            $book->getProperties()->setCreator('NEX-OMS');
            $sheet = $book->getActiveSheet()->setTitle('Rejestr sprzedaży');
            $this->checkRowCount(count($report['records']));
            $headers = $filters->includeKsef ? self::HEADERS : array_slice(self::HEADERS, 0, 19);
            $this->header($sheet, $headers, [6, 12, 23, 38, 30, 13, 22, 20, 16, 16, 18, 23, 18, 18, 25, 10, 22, 20, 30, 46, 20]);
            $warnings = $report['warnings'];
            $documents = [];
            foreach ($report['records'] as $index => $record) {
                $documents[$record['id']] = $record['number'] ?? 'ID '.$record['id'];
                $this->record($sheet, $index + 2, $record, $filters->includeKsef, $warnings);
            }
            $warnings = array_values(array_unique($warnings, SORT_REGULAR));
            if ($warnings !== []) {
                $this->checkRowCount(count($warnings));
                $notes = $book->createSheet()->setTitle('Uwagi');
                $this->header($notes, ['Dokument', 'Część raportu', 'Opis'], [30, 25, 100]);
                foreach ($warnings as $index => $warning) {
                    $row = $index + 2;
                    $this->text($notes, 'A'.$row, $documents[$warning['document_id']] ?? 'ID '.$warning['document_id']);
                    $this->text($notes, 'B'.$row, $this->labels->section($warning['section']));
                    $this->text($notes, 'C'.$row, $this->labels->warning($warning['code']));
                }
            }
            $book->setActiveSheetIndex(0);
            (new Xlsx($book))->setPreCalculateFormulas(false)->save($path);
            $filename = $filters->mode === 'ids' ? 'rejestr_sprzedazy_wybrane.xlsx'
                : 'rejestr_sprzedazy_'.str_replace('-', '', $filters->issueFrom).'_'.str_replace('-', '', $filters->issueTo).'.xlsx';
            $response = response()->download($path, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ])->setPrivate()->deleteFileAfterSend(true);
            $ready = true;

            return $response;
        } catch (InvoiceDomainException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvoiceDomainException('sales_register_xlsx_failed', 'Nie udało się przygotować pliku XLSX. Spróbuj ponownie.');
        } finally {
            $book?->disconnectWorksheets();
            if (! $ready && is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    private function header(Worksheet $sheet, array $headers, array $widths): void
    {
        foreach ($headers as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $this->text($sheet, $column.'1', $header);
            $sheet->getColumnDimension($column)->setWidth($widths[$index]);
        }
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $sheet->getParent()->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true);
        $sheet->getRowDimension(1)->setRowHeight(30);
    }

    private function record(Worksheet $sheet, int $row, array $record, bool $includeKsef, array &$warnings): void
    {
        $buyer = $record['buyer'];
        $address = $buyer['address'];
        $apartment = $address['apartment_number'] ?? null;
        $street = trim(($address['street'] ?? '').' '.($address['building_number'] ?? '').($apartment !== null && $apartment !== '' ? '/'.$apartment : ''));
        $related = array_map(fn (array $link) => ($link['type'] === 'correction' ? 'Korekta ' : 'Faktura ').($link['number'] ?? ''), $record['related_documents']);
        $rate = $record['currency'] === 'PLN' ? '1,000000' : ($record['exchange_rate']['rate'] ?? null);
        if ($rate === null && $record['currency'] !== null) {
            $warnings[] = $this->warning($record, 'exchange_rate_unavailable', 'exchange_rate');
        }
        $texts = [
            'B' => $record['type'] === 'correction' ? 'Korekta' : 'Faktura', 'C' => $record['number'],
            'D' => $buyer['name'], 'E' => $street, 'F' => $address['postal_code'], 'G' => $address['city'], 'H' => $buyer['tax_id'],
            'I' => $record['issue_date'], 'J' => $record['sale_date'], 'O' => $record['payment_method'],
            'P' => $record['currency'], 'Q' => $rate !== null ? str_replace('.', ',', $rate) : null,
            'R' => $buyer['country_name'] ?? $buyer['country_code'], 'S' => $related !== [] ? implode('; ', $related) : '-',
        ];
        if ($includeKsef) {
            $texts['T'] = $record['ksef_number'];
            $texts['U'] = $record['ksef_authorization_date'] !== null ? $this->labels->date($record['ksef_authorization_date']) : null;
        }
        foreach ($texts as $column => $value) {
            $this->text($sheet, $column.$row, $value);
        }
        $sheet->setCellValueExplicit('A'.$row, $record['ordinal'], DataType::TYPE_NUMERIC);
        $sheet->getStyle('A'.$row)->getNumberFormat()->setFormatCode('0');
        foreach (['K' => 'net', 'M' => 'vat', 'N' => 'gross'] as $column => $field) {
            $this->number($sheet, $column.$row, $record['totals'][$field] ?? null, $record, $warnings);
        }
        $groups = $record['vat_groups'] ?? [];
        if (count($groups) === 1 && $groups[0]['vat_code'] === null) {
            $this->number($sheet, 'L'.$row, $groups[0]['vat_rate'], $record, $warnings);
        } else {
            $this->text($sheet, 'L'.$row, $groups !== [] ? implode('; ', array_map(
                static fn (array $group) => $group['vat_code'] ?? str_replace('.', ',', $group['vat_rate']), $groups,
            )) : null);
        }
    }

    private function number(Worksheet $sheet, string $cell, ?string $value, array $record, array &$warnings): void
    {
        if ($value === null) {
            return;
        }
        if (! preg_match('/^-?(?:0|[1-9]\d*)\.\d{2}$/D', $value)) {
            throw new InvoiceDomainException('sales_register_xlsx_invalid_value', 'Nieprawidłowa kwota w danych rejestru.');
        }
        // Check decimal digits BEFORE crossing the writer's floating-point serialization boundary.
        $safe = strlen(ltrim(str_replace(['-', '.'], '', $value), '0')) <= 15;
        if ($safe) {
            $serialized = StringHelper::convertToString((float) $value);
            $safe = BigDecimal::of($serialized)->toScale(2, RoundingMode::HalfUp)->isEqualTo($value);
        }
        if (! $safe) {
            $this->text($sheet, $cell, $value);
            $warnings[] = $this->warning($record, 'xlsx_amount_as_text', 'totals');

            return;
        }
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('0.00');
    }

    private function text(Worksheet $sheet, string $cell, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! mb_check_encoding($value, 'UTF-8') || strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) > 32767 * 2) {
            throw new InvoiceDomainException('sales_register_xlsx_text_limit', 'Wartość tekstowa jest nieprawidłowa lub przekracza limit 32767 znaków komórki XLSX.');
        }
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('@');
    }

    private function checkRowCount(int $count): void
    {
        if ($count > 1048575) {
            throw new InvoiceDomainException('sales_register_xlsx_row_limit', 'Raport przekracza limit wierszy arkusza XLSX. Zawęź zakres dokumentów.');
        }
    }

    private function warning(array $record, string $code, string $section): array
    {
        return ['code' => 'sales_register_'.$code, 'document_id' => $record['id'], 'section' => $section];
    }
}
