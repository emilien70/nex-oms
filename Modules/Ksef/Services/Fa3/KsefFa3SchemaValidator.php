<?php

namespace Modules\Ksef\Services\Fa3;

use DOMDocument;
use LibXMLError;
use Modules\Invoices\Exceptions\InvoiceDomainException;

class KsefFa3SchemaValidator
{
    public const SCHEMA_ID = 'FA (3) 1-0E';

    private const SCHEMA_URLS = [
        'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/StrukturyDanych_v10-0E.xsd' => 'StrukturyDanych_v10-0E.xsd',
        'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/ElementarneTypyDanych_v10-0E.xsd' => 'ElementarneTypyDanych_v10-0E.xsd',
        'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/KodyKrajow_v10-0E.xsd' => 'KodyKrajow_v10-0E.xsd',
    ];

    public function validate(string $xml): void
    {
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $previousLoader = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(function (
            ?string $publicId,
            string $systemId,
        ) {
            $file = self::SCHEMA_URLS[$systemId] ?? null;
            if ($file === null) {
                return null;
            }

            return fopen($this->schemaDirectory().DIRECTORY_SEPARATOR.$file, 'rb');
        });

        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                throw $this->validationError(libxml_get_errors());
            }

            $schema = file_get_contents($this->schemaPath());
            if (! is_string($schema) || ! $document->schemaValidateSource($schema, LIBXML_NONET)) {
                throw $this->validationError(libxml_get_errors());
            }
        } finally {
            libxml_set_external_entity_loader($previousLoader);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    public function schemaPath(): string
    {
        return $this->schemaDirectory().DIRECTORY_SEPARATOR.'schemat_FA(3)_v1-0E.xsd';
    }

    public function manifestPath(): string
    {
        return $this->schemaDirectory().DIRECTORY_SEPARATOR.'manifest.json';
    }

    private function schemaDirectory(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'Schemas'
            .DIRECTORY_SEPARATOR.'FA3'.DIRECTORY_SEPARATOR.'1-0E';
    }

    /** @param array<int, LibXMLError> $errors */
    private function validationError(array $errors): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'ksef_fa3_schema_validation_failed',
            'Dokument XML nie jest zgodny z oficjalnym schematem FA(3).',
            [
                'schema' => self::SCHEMA_ID,
                'errors' => array_map(static fn (LibXMLError $error): array => [
                    'code' => $error->code,
                    'level' => $error->level,
                    'line' => $error->line,
                    'column' => $error->column,
                ], array_slice($errors, 0, 10)),
            ],
        );
    }
}
