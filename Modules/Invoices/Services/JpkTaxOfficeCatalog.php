<?php

namespace Modules\Invoices\Services;

use DOMDocument;
use DOMXPath;

final class JpkTaxOfficeCatalog
{
    private ?array $offices = null;

    /** @return array<string, string> */
    public function all(): array
    {
        if ($this->offices === null) {
            $document = new DOMDocument;
            $document->load(dirname(__DIR__).'/Resources/Schemas/JPK_V7M3/1-0E/KodyUrzedowSkarbowych_v8-0E.xsd', LIBXML_NONET);
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('xsd', 'http://www.w3.org/2001/XMLSchema');
            $this->offices = [];
            foreach ($xpath->query('//xsd:simpleType[@name="TKodUS"]/xsd:restriction/xsd:enumeration') as $entry) {
                $code = $entry->getAttribute('value');
                $this->offices[$code] = trim($xpath->evaluate('string(xsd:annotation/xsd:documentation)', $entry));
            }

            // Preserve codes and Polish alphabetical order independently of the host locale.
            $this->offices = collect($this->offices)->sortBy(
                fn (string $name): string => strtr(mb_strtolower($name, 'UTF-8'), [
                    'ą' => 'a~', 'ć' => 'c~', 'ę' => 'e~', 'ł' => 'l~', 'ń' => 'n~',
                    'ó' => 'o~', 'ś' => 's~', 'ź' => 'z~', 'ż' => 'z~~',
                ]),
                SORT_STRING,
            )->all();
        }

        return $this->offices;
    }

    public function contains(string $code): bool
    {
        return array_key_exists($code, $this->all());
    }
}
