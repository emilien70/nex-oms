<?php

namespace Modules\Ksef\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Modules\Ksef\Exceptions\KsefApiException;

class KsefUpoSchemaCompatibilityProjector
{
    public const UPO_NAMESPACE = 'http://upo.schematy.mf.gov.pl/KSeF/v4-3';

    public const XMLDSIG_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';

    public const XSD_RECEIVER_NAME = 'Ministerstwo Finansów';

    public function project(string $originalXml, string $expectedReceiverName): DOMDocument
    {
        $document = new DOMDocument;
        if (! $document->loadXML($originalXml, LIBXML_NONET) || $document->doctype !== null) {
            throw new KsefApiException(
                'Nie można utworzyć projekcji walidacyjnej dokumentu UPO.',
                'ksef_upo_schema_projection_failed',
            );
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('upo', self::UPO_NAMESPACE);
        $xpath->registerNamespace('ds', self::XMLDSIG_NAMESPACE);

        $signatures = $xpath->query('//ds:Signature');
        $rootSignatures = $xpath->query('/upo:Potwierdzenie/ds:Signature');
        if ($signatures->length !== 1 || $rootSignatures->length !== 1) {
            throw new KsefApiException(
                'Dokument UPO nie zawiera dokładnie jednego podpisu XMLDSig.',
                'ksef_upo_signature_invalid',
            );
        }

        $receiverNodes = $xpath->query('/upo:Potwierdzenie/upo:NazwaPodmiotuPrzyjmujacego');
        $receiver = $receiverNodes->length === 1 ? $receiverNodes->item(0) : null;
        if (! $receiver instanceof DOMElement
            || ! hash_equals($expectedReceiverName, $receiver->textContent)) {
            throw new KsefApiException(
                'Dokument UPO nie odpowiada środowisku KSeF.',
                'ksef_upo_receiver_mismatch',
            );
        }

        $signature = $rootSignatures->item(0);
        $signature?->parentNode?->removeChild($signature);
        $receiver->textContent = self::XSD_RECEIVER_NAME;

        return $document;
    }
}
