<?php

namespace Modules\Invoices\Services;

use DOMDocument;
use Illuminate\Support\Facades\File;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\ValueObjects\SalesRegisterFilters;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use XMLWriter;

final class SalesRegisterXmlExporter
{
    public function __construct(private readonly SalesRegisterXmlDetailsReader $details, private readonly SalesRegisterHtmlPresenter $labels) {}

    public function download(array $report, SalesRegisterFilters $filters): BinaryFileResponse
    {
        $path = null;
        $writer = null;
        $ready = false;
        try {
            $directory = storage_path('app/private/sales-register-exports');
            File::ensureDirectoryExists($directory, 0700);
            $candidate = $directory.DIRECTORY_SEPARATOR.'register-'.bin2hex(random_bytes(16)).'.xml';
            $handle = fopen($candidate, 'x+b');
            if ($handle === false) {
                throw new \RuntimeException('Temporary export file unavailable.');
            }
            $path = $candidate;
            fclose($handle);
            chmod($path, 0600);
            $writer = new XMLWriter;
            if (! $writer->openUri($path)) {
                throw new \RuntimeException('XML writer unavailable.');
            }
            $writer->setIndent(true);
            $writer->startDocument('1.0', 'utf-8');
            $writer->startElement('invoices');
            $warnings = $report['warnings'];
            foreach (array_chunk($report['records'], SalesRegisterDataService::BATCH_SIZE) as $batch) {
                foreach ($this->details->read($batch, $filters->includeKsef) as $document) {
                    $writer->startElement('invoice');
                    $this->elements($writer, $document['fields'], $document['id']);
                    $writer->endElement();
                    array_push($warnings, ...$document['warnings']);
                }
            }
            foreach (array_values(array_unique($warnings, SORT_REGULAR)) as $warning) {
                $text = 'NEX-OMS | ID '.$warning['document_id'].' | '.$warning['code'].' | '.$this->labels->warning($warning['code']);
                $this->details->assertXmlText($text, (int) $warning['document_id'], 'warning');
                // XML comments cannot contain "--" or end in "-"; comments carry no document values.
                while (str_contains($text, '--')) {
                    $text = str_replace('--', '- -', $text);
                }
                $writer->writeComment(' '.$text.' ');
            }
            $writer->endElement();
            $writer->endDocument();
            $writer->flush();
            $writer = null;
            $this->verify($path);
            $filename = $filters->mode === 'ids' ? 'rejestr_sprzedazy_wybrane.xml'
                : 'rejestr_sprzedazy_'.str_replace('-', '', $filters->issueFrom).'_'.str_replace('-', '', $filters->issueTo).'.xml';
            $response = response()->download($path, $filename, [
                'Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ])->setPrivate()->deleteFileAfterSend(true);
            $ready = true;

            return $response;
        } catch (InvoiceDomainException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvoiceDomainException('sales_register_xml_failed', 'Nie udało się przygotować pliku XML. Spróbuj ponownie.');
        } finally {
            $writer = null;
            if (! $ready && is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    private function elements(XMLWriter $writer, array $fields, int $id): void
    {
        foreach ($fields as $name => $value) {
            if (is_array($value) && array_is_list($value) && $value !== []) {
                foreach ($value as $item) {
                    $writer->startElement($name);
                    $this->elements($writer, $item, $id);
                    $writer->endElement();
                }
            } else {
                $writer->startElement($name);
                if (is_array($value)) {
                    $this->elements($writer, $value, $id);
                } elseif ($value !== null && $value !== '') {
                    $this->details->assertXmlText($value, $id, $name);
                    $writer->text($value);
                }
                $writer->endElement();
            }
        }
    }

    private function verify(string $path): void
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (! $document->load($path, LIBXML_NONET) || $document->doctype !== null || $document->documentElement?->nodeName !== 'invoices') {
                throw new \RuntimeException('Invalid generated XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
