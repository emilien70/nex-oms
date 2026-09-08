<?php

namespace Modules\Ksef\Services\Fa3;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Modules\Ksef\Exceptions\KsefApiException;

final class KsefFa3CorrectionFinancialEvidencePayloadValidator
{
    public function __construct(
        private readonly KsefFa3CorrectionFinancialEvidenceValidator $evidence,
    ) {}

    /** @param array<string, mixed> $evidence */
    public function validate(string $xml, array $evidence): void
    {
        try {
            $this->evidence->validate($evidence);
            $xpath = $this->xpath($xml);
            if ($this->required($xpath, '/fa:Faktura/fa:Fa/fa:RodzajFaktury') !== 'KOR'
                || $this->required($xpath, '/fa:Faktura/fa:Fa/fa:KodWaluty') !== $evidence['currency']
                || $this->required($xpath, '/fa:Faktura/fa:Fa/fa:P_15') !== $evidence['totals']['gross']) {
                throw $this->invalid();
            }

            $pairs = $this->linePairs($xpath);
            if (count($pairs) !== count($evidence['lines'])) {
                throw $this->invalid();
            }
            foreach ($evidence['lines'] as $line) {
                $pair = $pairs[$line['position']] ?? null;
                if ($pair === null) {
                    throw $this->invalid();
                }
                foreach (['before', 'after'] as $side) {
                    if ($pair[$side]['total_net'] !== $line[$side]['total_net']
                        || $pair[$side]['fa3_rate'] !== $line[$side]['fa3_rate']) {
                        throw $this->invalid();
                    }
                }
            }

            $allowedFields = [];
            foreach (KsefFa3CorrectionTaxBuckets::FIELDS as $bucket => $fields) {
                foreach ($fields as $amount => $field) {
                    $allowedFields[] = $field;
                    if ($this->optional($xpath, '/fa:Faktura/fa:Fa/fa:'.$field)
                        !== ($evidence['tax_buckets'][$bucket][$amount] ?? null)) {
                        throw $this->invalid();
                    }
                }
            }
            foreach ($this->nodes(
                $xpath,
                '/fa:Faktura/fa:Fa/*[starts-with(local-name(), "P_13_") or starts-with(local-name(), "P_14_")]',
            ) as $node) {
                if (! in_array($node->localName, $allowedFields, true)) {
                    throw $this->invalid();
                }
            }
        } catch (KsefApiException) {
            throw $this->invalid();
        }
    }

    /** @return array<int, array{before: array{total_net: string, fa3_rate: string}, after: array{total_net: string, fa3_rate: string}}> */
    private function linePairs(DOMXPath $xpath): array
    {
        $pairs = [];
        foreach ($this->nodes($xpath, '/fa:Faktura/fa:Fa/fa:FaWiersz') as $node) {
            if (! $node instanceof DOMElement) {
                throw $this->invalid();
            }
            $position = $this->required($xpath, './fa:NrWierszaFa', $node);
            if (preg_match('/^[1-9][0-9]*$/D', $position) !== 1) {
                throw $this->invalid();
            }
            $before = $this->optional($xpath, './fa:StanPrzed', $node);
            if ($before !== null && $before !== '1') {
                throw $this->invalid();
            }
            $side = $before === '1' ? 'before' : 'after';
            if (isset($pairs[(int) $position][$side])) {
                throw $this->invalid();
            }
            $pairs[(int) $position][$side] = [
                'total_net' => $this->required($xpath, './fa:P_11', $node),
                'fa3_rate' => $this->required($xpath, './fa:P_12', $node),
            ];
        }
        foreach ($pairs as $pair) {
            if (! isset($pair['before'], $pair['after'])) {
                throw $this->invalid();
            }
        }

        return $pairs;
    }

    private function xpath(string $xml): DOMXPath
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw $this->invalid();
        }
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $document->validateOnParse = false;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded
            || $document->doctype !== null
            || $document->documentElement?->localName !== 'Faktura'
            || $document->documentElement?->namespaceURI !== KsefFa3XmlBuilder::NAMESPACE) {
            throw $this->invalid();
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fa', KsefFa3XmlBuilder::NAMESPACE);

        return $xpath;
    }

    private function required(DOMXPath $xpath, string $expression, ?DOMNode $context = null): string
    {
        $nodes = $this->nodes($xpath, $expression, $context);
        $value = $nodes->length === 1 ? trim($nodes->item(0)?->textContent ?? '') : '';
        if ($value === '') {
            throw $this->invalid();
        }

        return $value;
    }

    private function optional(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?string
    {
        $nodes = $this->nodes($xpath, $expression, $context);
        if ($nodes->length > 1) {
            throw $this->invalid();
        }
        if ($nodes->length === 0) {
            return null;
        }
        $value = trim($nodes->item(0)?->textContent ?? '');
        if ($value === '') {
            throw $this->invalid();
        }

        return $value;
    }

    private function nodes(DOMXPath $xpath, string $expression, ?DOMNode $context = null): \DOMNodeList
    {
        $nodes = $xpath->query($expression, $context);
        if ($nodes === false) {
            throw $this->invalid();
        }

        return $nodes;
    }

    private function invalid(): KsefApiException
    {
        return new KsefApiException(
            'Zamrożone dane finansowe Korekty Offline są niekompletne lub niespójne.',
            'ksef_offline_presentation_integrity_invalid',
        );
    }
}
