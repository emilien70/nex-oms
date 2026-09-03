<?php

namespace Modules\Ksef\Services\Fa3;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Modules\Ksef\Exceptions\KsefApiException;

class KsefFa3IssueDateReader
{
    public function read(string $xml): string
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw $this->invalidIssueDate();
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
            || $document->documentElement?->localName !== 'Faktura'
            || $document->documentElement?->namespaceURI !== KsefFa3XmlBuilder::NAMESPACE) {
            throw $this->invalidIssueDate();
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fa', KsefFa3XmlBuilder::NAMESPACE);
        $nodes = $xpath->query('/fa:Faktura/fa:Fa/fa:P_1');

        if ($nodes === false || $nodes->length !== 1) {
            throw $this->invalidIssueDate();
        }

        $value = trim($nodes->item(0)?->textContent ?? '');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw $this->invalidIssueDate();
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Europe/Warsaw');
        $errors = CarbonImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw $this->invalidIssueDate();
        }

        return $value;
    }

    private function invalidIssueDate(): KsefApiException
    {
        return new KsefApiException(
            'Zamrożony dokument FA(3) nie zawiera jednej prawidłowej daty wystawienia P_1.',
            'ksef_online_submission_issue_date_invalid',
        );
    }
}
