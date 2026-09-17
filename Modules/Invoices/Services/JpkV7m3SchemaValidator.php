<?php

namespace Modules\Invoices\Services;

use DOMDocument;
use Modules\Invoices\Exceptions\InvoiceDomainException;

final class JpkV7m3SchemaValidator
{
    public const NS = 'http://crd.gov.pl/wzor/2025/12/19/14090/';

    public const TYPES_NS = 'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/09/13/eD/DefinicjeTypy/';

    private const IMPORTS = [
        'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2023/09/06/eD/KodyKrajow/KodyKrajow_v13-0E.xsd' => 'KodyKrajow_v13-0E.xsd',
        'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/KodyUrzedowSkarbowych/KodyUrzedowSkarbowych_v8-0E.xsd' => 'KodyUrzedowSkarbowych_v8-0E.xsd',
        'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/09/13/eD/DefinicjeTypy/StrukturyDanych_v12-0E.xsd' => 'StrukturyDanych_v12-0E.xsd',
    ];

    /** Returns only safe diagnostics, never libxml messages containing input values. */
    public function errors(string $xml): array
    {
        if (! mb_check_encoding($xml, 'UTF-8') || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            return [['code' => 'xml_unsafe', 'line' => 0]];
        }
        $directory = dirname(__DIR__).'/Resources/Schemas/JPK_V7M3/1-0E/';
        $schema = realpath($directory.'schemat.xsd');
        $allowed = [];
        foreach (self::IMPORTS as $url => $file) {
            $allowed[$url] = realpath($directory.$file);
        }
        $previousErrors = libxml_use_internal_errors(true);
        $previousLoader = libxml_get_external_entity_loader();
        libxml_clear_errors();
        libxml_set_external_entity_loader(static function ($public, $system) use ($allowed, $schema) {
            $path = $system === $schema ? $schema : ($allowed[$system] ?? null);

            return is_string($path) ? fopen($path, 'rb') : null;
        });
        try {
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            $valid = $document->loadXML($xml, LIBXML_NONET) && $document->doctype === null
                && $schema !== false && $document->schemaValidate($schema);
            $locations = [];
            foreach ($document->getElementsByTagName('*') as $element) {
                $row = $element;
                while ($row !== null && $row->localName !== 'SprzedazWiersz') {
                    $row = $row->parentNode;
                }
                $locations[$element->getLineNo()] = ['field' => $element->localName,
                    'row' => $row === null ? null : (int) $row->getElementsByTagNameNS(self::NS, 'LpSprzedazy')->item(0)?->textContent];
            }
            $errors = array_map(static fn ($error) => ['code' => $error->code, 'line' => $error->line] + ($locations[$error->line] ?? []), libxml_get_errors());

            return $valid ? [] : ($errors ?: [['code' => 'xsd_invalid', 'line' => 0]]);
        } finally {
            libxml_set_external_entity_loader($previousLoader);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    public function validate(string $xml, array $documentIds = []): void
    {
        $errors = $this->errors($xml);
        if ($errors !== []) {
            $error = $errors[0];
            $id = $documentIds[($error['row'] ?? 0) - 1] ?? null;
            throw new InvoiceDomainException('sales_register_jpk_invalid', ($id === null ? '' : 'Dokument ID '.$id.', ')
                .'pole '.($error['field'] ?? 'XML').': plik nie przeszedł walidacji oficjalnym XSD JPK_V7M(3) (kod '.$error['code'].', wiersz '.$error['line'].'). Sprawdź wymaganą wartość, długość i format.');
        }
    }
}
