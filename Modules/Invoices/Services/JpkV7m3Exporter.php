<?php

namespace Modules\Invoices\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Services\KsefFa3BuyerIdentityResolver;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use XMLWriter;

final class JpkV7m3Exporter
{
    public function __construct(private readonly JpkV7m3TaxMapper $tax, private readonly JpkV7m3KsefPolicy $ksef,
        private readonly JpkV7m3SchemaValidator $schema, private readonly KsefFa3BuyerIdentityResolver $identities,
        private readonly InvoiceDecimalCalculator $decimal) {}

    public function prepare(array $report, JpkV7m3Context $context, array $confirmations, bool $bfkOutsideKsef = false): array
    {
        $ids = array_column($report['records'], 'id');
        foreach ($confirmations as $id => $value) {
            if (! in_array((int) $id, $ids, true) || (string) (int) $id !== (string) $id || ! is_string($value)
                || ! in_array($value, ['', 'BFK', 'OFF', 'DI'], true)) {
                JpkV7m3Context::fail(0, 'potwierdzenia KSeF', 'Potwierdzenia muszą dotyczyć dokładnie wybranych dokumentów.');
            }
        }
        $rows = $review = $errors = $diagnostics = [];
        foreach (array_chunk($report['records'], SalesRegisterDataService::BATCH_SIZE) as $batch) {
            $batchIds = array_column($batch, 'id');
            $documents = Invoice::query()->whereIn('id', $batchIds)->select([
                'id', 'number', 'document_type', 'buyer_snapshot', 'buyer_name_snapshot', 'buyer_tax_id_snapshot',
                'seller_snapshot', 'seller_tax_id_snapshot', 'tax_metadata_snapshot', 'status', 'finalized_at',
            ])->with(['items' => fn ($q) => $q->select(['id', 'invoice_id', 'source_invoice_item_id', 'position', 'vat_rate', 'vat_code',
                'total_net', 'total_vat', 'total_gross', 'gtu_codes', 'correction_before_snapshot', 'correction_after_snapshot'])])->get()->keyBy('id');
            $outside = DB::table('ksef_invoice_provenances')->whereIn('invoice_id', $batchIds)->where('environment', 'production')
                ->get(['invoice_id', 'provenance'])->groupBy('invoice_id');
            $offline = DB::table('ksef_offline_issuances')->whereIn('invoice_id', $batchIds)->where('environment', 'production')
                ->get(['id', 'invoice_id', 'procedure', 'issue_date', 'seller_nip', 'context_identifier_type', 'context_identifier_value', 'invoice_hash', 'invoice_size', 'schema_id'])->groupBy('invoice_id');
            $submissions = DB::table('ksef_invoice_submissions')->whereIn('invoice_id', $batchIds)->where('environment', 'production')
                ->get(['invoice_id', 'status', 'offline_issuance_id'])->groupBy('invoice_id');
            foreach ($batch as $record) {
                $id = $record['id'];
                $document = $documents->get($id);
                $evidence = [];
                foreach (['outside' => $outside, 'offline' => $offline, 'submissions' => $submissions] as $key => $collection) {
                    $evidence[$key] = $collection->get($id, collect())->map(static fn ($row) => (array) $row)->all();
                }
                $problem = [];
                $issues = [];
                $amounts = null;
                $choice = [];
                $manual = false;
                try {
                    if ($document === null) {
                        JpkV7m3Context::fail($id, 'dokument', 'Dokument nie jest już dostępny.');
                    }
                    $nip = $this->seller($document, $context);
                    $fields = $this->row($record, $document);
                    $choice = $this->ksef->resolve($record, $evidence, $nip, ($confirmations[$id] ?? '') ?: null,
                        $bfkOutsideKsef);
                    $manual = $record['ksef_number'] === null && $evidence === ['outside' => [], 'offline' => [], 'submissions' => []];
                    $amounts = $this->tax->map($record, $document);
                    $rows[] = $fields + $choice + $amounts;
                    if ($choice === []) {
                        JpkV7m3Context::fail($id, 'KSeF', 'Potwierdź faktyczny sposób wystawienia (KSeF).', 'ksef_confirmation_required');
                    }
                } catch (InvoiceDomainException $exception) {
                    if (! str_starts_with($exception->errorCode(), 'sales_register_jpk_')) {
                        throw $exception;
                    }
                    $problem[] = $exception->getMessage();
                    $issues[] = JpkV7m3Diagnostic::from($exception);
                }
                array_push($errors, ...$problem);
                $review[] = ['id' => $id, 'number' => $record['number'], 'issue_date' => $record['issue_date'], 'choice' => $choice,
                    'manual' => $manual, 'confirmation' => $confirmations[$id] ?? '', 'errors' => $problem,
                    'gtu' => $amounts === null ? null : array_values(array_intersect(array_keys($amounts), InvoiceGtuCodes::ALLOWED)),
                    'markers' => $amounts === null ? null : array_values(array_filter(array_keys($amounts),
                        static fn ($key) => ! in_array($key, InvoiceGtuCodes::ALLOWED, true) && ! str_starts_with($key, 'K_'))),
                    'diagnostics' => $issues, 'status' => $document?->status->label(), 'finalized' => $document?->isFinalized() ?? false,
                    'url' => route($record['type'] === 'correction' ? 'invoices.corrections.edit' : 'invoices.edit', $id)];
            }
        }
        $xml = null;
        if ($errors === []) {
            try {
                $xml = $this->serialize($rows, $context);
                $this->schema->validate($xml, $ids);
            } catch (InvoiceDomainException $exception) {
                $errors[] = $exception->getMessage();
                $diagnostics[] = JpkV7m3Diagnostic::from($exception);
            }
        }

        return ['documents' => $review, 'errors' => $errors, 'diagnostics' => $diagnostics, 'xml' => $xml,
            'selection' => $report['selection'], 'selection_warnings' => array_values(array_filter($report['warnings'], static fn ($warning) => $warning['section'] === 'selection'))];
    }

    private function seller(Invoice $document, JpkV7m3Context $context): string
    {
        $snapshot = $document->seller_snapshot;
        if ($snapshot !== null && ! is_array($snapshot)) {
            JpkV7m3Context::fail($document->id, 'sprzedawca', 'Nieprawidłowy snapshot sprzedawcy.');
        }
        $raw = is_array($snapshot) && array_key_exists('tax_id', $snapshot) ? $snapshot['tax_id'] : $document->seller_tax_id_snapshot;
        $nip = is_string($raw) ? $this->identities->normalizePolishNip($raw) : null;
        $scalar = $document->seller_tax_id_snapshot;
        if ($nip === null || $nip !== $context->data['jpk_nip'] || ($scalar !== null && (! is_string($scalar) || $this->identities->normalizePolishNip($scalar) !== $nip))) {
            JpkV7m3Context::fail($document->id, 'NIP wystawcy', 'Nie jest zgodny z podatnikiem lub snapshot jest niespójny.');
        }

        return $nip;
    }

    private function row(array $record, Invoice $document): array
    {
        $id = $record['id'];
        $buyer = $record['buyer'];
        foreach (['name_state', 'tax_id_state'] as $state) {
            if (in_array($buyer[$state], ['invalid', 'conflict', 'missing'], true)) {
                JpkV7m3Context::fail($id, 'kontrahent', 'Brak wiarygodnego snapshotu lub sprzeczne dane kontrahenta.');
            }
        }
        foreach (['number', 'buyer_name_snapshot', 'buyer_tax_id_snapshot'] as $field) {
            JpkV7m3Context::text($document->getRawOriginal($field), $id, $field);
        }
        foreach (['name', 'company_name', 'tax_id'] as $field) {
            JpkV7m3Context::text(data_get($document->buyer_snapshot, $field), $id, 'buyer.'.$field);
        }
        $fields = ['LpSprzedazy' => (string) $record['ordinal']];
        $taxId = $buyer['tax_id'];
        $identity = data_get($document->buyer_snapshot, 'tax_identity');
        if ($taxId === null) {
            if ($identity !== null && (! is_array($identity) || ($identity['version'] ?? null) !== 1 || ($identity['type'] ?? null) !== 'none'
                || ($identity['status'] ?? null) !== 'resolved' || ($identity['identifier'] ?? null) !== null)) {
                JpkV7m3Context::fail($id, 'NrKontrahenta', 'Niespójna tożsamość podatkowa; brak nie może maskować błędu.');
            }
            $fields['NrKontrahenta'] = 'BRAK';
        } else {
            $compact = preg_replace('/[\s.-]+/u', '', strtoupper($taxId));
            if (! is_array($identity) || ($identity['version'] ?? null) !== 1 || ($identity['status'] ?? null) !== 'resolved') {
                JpkV7m3Context::fail($id, 'NrKontrahenta', 'Brak jednoznacznego historycznego rodzaju identyfikatora podatkowego.');
            }
            $country = $identity['country_code'] ?? null;
            $identifier = $identity['identifier'] ?? null;
            if (($identity['type'] ?? null) === 'pl_nip') {
                if ($country !== 'PL' || ! is_string($identifier) || $this->identities->normalizePolishNip($compact) !== $identifier) {
                    JpkV7m3Context::fail($id, 'NrKontrahenta', 'Niespójny polski NIP.');
                }
            } elseif (($identity['type'] ?? null) === 'eu_vat') {
                if (! in_array($country, ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI'], true)
                    || ! is_string($identifier) || ! preg_match('/^[A-Z0-9+*]{1,12}$/D', $identifier) || $compact !== $country.$identifier) {
                    JpkV7m3Context::fail($id, 'NrKontrahenta', 'Niespójny prefiks lub zagraniczny identyfikator VAT.');
                }
            } else {
                JpkV7m3Context::fail($id, 'NrKontrahenta', 'Ten rodzaj identyfikatora wymaga potwierdzonego mapowania.');
            }
            $fields['KodKrajuNadaniaTIN'] = $country;
            $fields['NrKontrahenta'] = $identifier;
        }
        $fields['NazwaKontrahenta'] = $buyer['name'] ?? 'BRAK';
        $fields['DowodSprzedazy'] = JpkV7m3Context::text($record['number'], $id, 'DowodSprzedazy', true);
        if ($record['issue_date'] === null || $record['sale_date'] === null) {
            JpkV7m3Context::fail($id, 'daty', 'Brak prawidłowych dat dokumentu.');
        }
        $fields['DataWystawienia'] = $record['issue_date'];
        if ($record['sale_date'] !== $record['issue_date']) {
            $fields['DataSprzedazy'] = $record['sale_date'];
        }

        return $fields;
    }

    public function controlTax(array $rows): string
    {
        $sum = '0.00';
        foreach ($rows as $row) {
            if (($row['TypDokumentu'] ?? null) === 'FP') {
                continue;
            }
            foreach (['K_16', 'K_18', 'K_20', 'K_24', 'K_26', 'K_28', 'K_30', 'K_32', 'K_33', 'K_34'] as $field) {
                $sum = $this->decimal->add($sum, $row[$field] ?? '0.00');
            }
            foreach (['K_35', 'K_36', 'K_360'] as $field) {
                $sum = $this->decimal->subtract($sum, $row[$field] ?? '0.00');
            }
        }

        return $sum;
    }

    private function serialize(array $rows, JpkV7m3Context $context): string
    {
        $data = $context->data;
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'utf-8');
        $writer->startElementNS(null, 'JPK', JpkV7m3SchemaValidator::NS);
        $writer->writeAttributeNS('xmlns', 'etd', null, JpkV7m3SchemaValidator::TYPES_NS);
        $writer->startElement('Naglowek');
        $writer->startElement('KodFormularza');
        $writer->writeAttribute('kodSystemowy', 'JPK_V7M (3)');
        $writer->writeAttribute('wersjaSchemy', '1-0E');
        $writer->text('JPK_VAT');
        $writer->endElement();
        $this->elements($writer, ['WariantFormularza' => '3', 'DataWytworzeniaJPK' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'NazwaSystemu' => 'NEX-OMS']);
        $writer->startElement('CelZlozenia');
        $writer->writeAttribute('poz', 'P_7');
        $writer->text($data['jpk_purpose']);
        $writer->endElement();
        $this->elements($writer, ['KodUrzedu' => $data['jpk_office'], 'Rok' => $data['jpk_year'], 'Miesiac' => (string) (int) $data['jpk_month']]);
        $writer->endElement();
        $writer->startElement('Podmiot1');
        $writer->writeAttribute('rola', 'Podatnik');
        $writer->startElement($data['jpk_type'] === 'person' ? 'OsobaFizyczna' : 'OsobaNiefizyczna');
        $identity = ['NIP' => $data['jpk_nip']] + ($data['jpk_type'] === 'person'
            ? ['ImiePierwsze' => $data['jpk_first_name'], 'Nazwisko' => $data['jpk_last_name'], 'DataUrodzenia' => $data['jpk_birth_date']]
            : ['PelnaNazwa' => $data['jpk_name']]);
        foreach ($identity as $field => $value) {
            $writer->writeElement(($data['jpk_type'] === 'person' ? 'etd:' : '').$field, $value);
        }
        $this->elements($writer, ['Email' => $data['jpk_email']]);
        if (($data['jpk_phone'] ?? '') !== '') {
            $this->elements($writer, ['Telefon' => $data['jpk_phone']]);
        }
        $writer->endElement();
        $writer->endElement();
        $writer->startElement('Ewidencja');
        foreach ($rows as $row) {
            $writer->startElement('SprzedazWiersz');
            $this->elements($writer, $row);
            $writer->endElement();
        }
        $writer->startElement('SprzedazCtrl');
        $this->elements($writer, ['LiczbaWierszySprzedazy' => (string) count($rows), 'PodatekNalezny' => $this->controlTax($rows)]);
        $writer->endElement();
        $writer->startElement('ZakupCtrl');
        $this->elements($writer, ['LiczbaWierszyZakupow' => '0', 'PodatekNaliczony' => '0.00']);
        $writer->endElement();
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    private function elements(XMLWriter $writer, array $fields): void
    {
        foreach ($fields as $field => $value) {
            $writer->writeElement($field, JpkV7m3Context::text($value, 0, $field, true));
        }
    }

    public function download(array $prepared, JpkV7m3Context $context): BinaryFileResponse
    {
        if ($prepared['errors'] !== [] || ! is_string($prepared['xml'])) {
            JpkV7m3Context::fail(0, 'gotowość', 'Usuń błędy przed pobraniem.');
        }
        $path = null;
        try {
            $this->schema->validate($prepared['xml']);
            $directory = storage_path('app/private/sales-register-exports');
            File::ensureDirectoryExists($directory, 0700);
            $candidate = $directory.'/jpk-'.bin2hex(random_bytes(16)).'.xml';
            $handle = fopen($candidate, 'x+b');
            if ($handle === false) {
                throw new \RuntimeException;
            }
            $path = $candidate;
            try {
                chmod($path, 0600);
                if (fwrite($handle, $prepared['xml']) !== strlen($prepared['xml'])) {
                    throw new \RuntimeException;
                }
            } finally {
                fclose($handle);
            }
            $this->schema->validate(file_get_contents($path));

            return response()->download($path, sprintf('jpk_v7m_3_sprzedaz_%04d_%02d.xml', $context->data['jpk_year'], $context->data['jpk_month']), [
                'Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ])->setPrivate()->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            if ($path !== null && is_file($path)) {
                unlink($path);
            }
            if ($exception instanceof InvoiceDomainException) {
                throw $exception;
            }
            throw new InvoiceDomainException('sales_register_jpk_invalid', 'Nie udało się przygotować pliku JPK. Spróbuj ponownie.');
        }
    }
}
