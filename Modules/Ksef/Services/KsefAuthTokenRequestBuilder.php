<?php

namespace Modules\Ksef\Services;

use DOMDocument;
use Modules\Ksef\Exceptions\KsefApiException;

final class KsefAuthTokenRequestBuilder
{
    public const NAMESPACE = 'http://ksef.mf.gov.pl/auth/token/2.1';

    public function build(string $challenge, string $contextNip): string
    {
        if ($challenge === '' || preg_match('/^\d{10}$/', $contextNip) !== 1) {
            throw new KsefApiException(
                'Nie udało się przygotować żądania uwierzytelnienia certyfikatem.',
                'auth_xml_input_invalid',
            );
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $document->preserveWhiteSpace = false;

        $root = $document->createElementNS(self::NAMESPACE, 'AuthTokenRequest');
        $document->appendChild($root);
        $root->appendChild($document->createElementNS(self::NAMESPACE, 'Challenge', $challenge));

        $contextIdentifier = $document->createElementNS(self::NAMESPACE, 'ContextIdentifier');
        $contextIdentifier->appendChild($document->createElementNS(self::NAMESPACE, 'Nip', $contextNip));
        $root->appendChild($contextIdentifier);
        $root->appendChild($document->createElementNS(
            self::NAMESPACE,
            'SubjectIdentifierType',
            'certificateSubject',
        ));

        $xml = $document->saveXML();

        if (! is_string($xml)) {
            throw new KsefApiException(
                'Nie udało się przygotować żądania uwierzytelnienia certyfikatem.',
                'auth_xml_build_failed',
            );
        }

        return $xml;
    }
}
