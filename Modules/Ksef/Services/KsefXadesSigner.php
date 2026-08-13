<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Modules\Ksef\Exceptions\KsefApiException;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use phpseclib3\File\ASN1\Element;
use phpseclib3\File\X509;
use phpseclib3\Math\BigInteger;

final class KsefXadesSigner
{
    public const DS_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';

    public const XADES_NAMESPACE = 'http://uri.etsi.org/01903/v1.3.2#';

    public const EXCLUSIVE_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    public const SHA256_DIGEST = 'http://www.w3.org/2001/04/xmlenc#sha256';

    public const SIGNED_PROPERTIES_TYPE = 'http://uri.etsi.org/01903#SignedProperties';

    private const ENVELOPED_SIGNATURE = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    private const RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    private const ECDSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256';

    public function __construct(
        private readonly KsefEcdsaSignatureConverter $ecdsa,
    ) {}

    public function sign(string $unsignedXml, string $certificatePem, string $privateKeyPem): string
    {
        $document = $this->loadDocument($unsignedXml);
        $certificate = @openssl_x509_read($certificatePem);
        $privateKey = @openssl_pkey_get_private($privateKeyPem);

        if (! $certificate instanceof OpenSSLCertificate || ! $privateKey instanceof OpenSSLAsymmetricKey) {
            $this->fail();
        }

        $certificateData = openssl_x509_parse($certificate, false);
        $privateKeyDetails = openssl_pkey_get_details($privateKey);

        if (! is_array($certificateData) || ! is_array($privateKeyDetails)) {
            $this->fail();
        }

        [$signatureMethod, $isEc] = $this->signatureMethod($privateKeyDetails);
        $certificateDer = $this->certificateDer($certificatePem);
        $root = $document->documentElement;

        if (! $root instanceof DOMElement) {
            $this->fail();
        }

        $signature = $this->ds($document, 'Signature');
        $signature->setAttribute('Id', 'Signature');
        $signedInfo = $this->buildSignedInfo($document, $signatureMethod);
        $signatureValue = $this->ds($document, 'SignatureValue');
        $keyInfo = $this->buildKeyInfo($document, $certificateDer);
        [$object, $signedProperties] = $this->buildQualifyingProperties(
            $document,
            $certificatePem,
            $certificateData,
            $certificateDer,
        );

        $signature->appendChild($signedInfo);
        $signature->appendChild($signatureValue);
        $signature->appendChild($keyInfo);
        $signature->appendChild($object);
        $root->appendChild($signature);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', self::DS_NAMESPACE);
        $references = $xpath->query('./ds:Reference', $signedInfo);

        if ($references === false || $references->length !== 2) {
            $this->fail();
        }

        $rootDigest = $this->digest($this->canonicalRootWithoutSignature($document));
        $propertiesDigest = $this->digest($this->canonicalize($signedProperties));
        $this->setReferenceDigest($document, $references->item(0), $rootDigest);
        $this->setReferenceDigest($document, $references->item(1), $propertiesDigest);

        $canonicalSignedInfo = $this->canonicalize($signedInfo);
        $rawSignature = '';

        if (! openssl_sign($canonicalSignedInfo, $rawSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            $this->fail();
        }

        $signatureValue->nodeValue = base64_encode($isEc
            ? $this->ecdsa->derToRaw($rawSignature)
            : $rawSignature);
        $xml = $document->saveXML();

        if (! is_string($xml)) {
            $this->fail();
        }

        return $xml;
    }

    private function loadDocument(string $xml): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $document->preserveWhiteSpace = false;

        if (! @$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)
            || $document->documentElement?->localName !== 'AuthTokenRequest'
            || $document->documentElement?->namespaceURI !== KsefAuthTokenRequestBuilder::NAMESPACE) {
            $this->fail();
        }

        return $document;
    }

    private function buildSignedInfo(DOMDocument $document, string $signatureMethod): DOMElement
    {
        $signedInfo = $this->ds($document, 'SignedInfo');
        $canonicalization = $this->ds($document, 'CanonicalizationMethod');
        $canonicalization->setAttribute('Algorithm', self::EXCLUSIVE_C14N);
        $signedInfo->appendChild($canonicalization);
        $method = $this->ds($document, 'SignatureMethod');
        $method->setAttribute('Algorithm', $signatureMethod);
        $signedInfo->appendChild($method);

        $rootReference = $this->ds($document, 'Reference');
        $rootReference->setAttribute('URI', '');
        $transforms = $this->ds($document, 'Transforms');

        foreach ([self::ENVELOPED_SIGNATURE, self::EXCLUSIVE_C14N] as $algorithm) {
            $transform = $this->ds($document, 'Transform');
            $transform->setAttribute('Algorithm', $algorithm);
            $transforms->appendChild($transform);
        }

        $rootReference->appendChild($transforms);
        $this->appendDigestPlaceholders($document, $rootReference);
        $signedInfo->appendChild($rootReference);

        $propertiesReference = $this->ds($document, 'Reference');
        $propertiesReference->setAttribute('URI', '#SignedProperties');
        $propertiesReference->setAttribute('Type', self::SIGNED_PROPERTIES_TYPE);
        $propertiesTransforms = $this->ds($document, 'Transforms');
        $propertiesTransform = $this->ds($document, 'Transform');
        $propertiesTransform->setAttribute('Algorithm', self::EXCLUSIVE_C14N);
        $propertiesTransforms->appendChild($propertiesTransform);
        $propertiesReference->appendChild($propertiesTransforms);
        $this->appendDigestPlaceholders($document, $propertiesReference);
        $signedInfo->appendChild($propertiesReference);

        return $signedInfo;
    }

    private function appendDigestPlaceholders(DOMDocument $document, DOMElement $reference): void
    {
        $method = $this->ds($document, 'DigestMethod');
        $method->setAttribute('Algorithm', self::SHA256_DIGEST);
        $reference->appendChild($method);
        $reference->appendChild($this->ds($document, 'DigestValue'));
    }

    private function buildKeyInfo(DOMDocument $document, string $certificateDer): DOMElement
    {
        $keyInfo = $this->ds($document, 'KeyInfo');
        $x509Data = $this->ds($document, 'X509Data');
        $x509Data->appendChild($this->ds(
            $document,
            'X509Certificate',
            base64_encode($certificateDer),
        ));
        $keyInfo->appendChild($x509Data);

        return $keyInfo;
    }

    private function buildQualifyingProperties(
        DOMDocument $document,
        string $certificatePem,
        array $certificateData,
        string $certificateDer,
    ): array {
        $object = $this->ds($document, 'Object');
        $qualifying = $document->createElementNS(self::XADES_NAMESPACE, 'xades:QualifyingProperties');
        $qualifying->setAttribute('Target', '#Signature');
        $signedProperties = $document->createElementNS(self::XADES_NAMESPACE, 'xades:SignedProperties');
        $signedProperties->setAttribute('Id', 'SignedProperties');
        $signedSignatureProperties = $document->createElementNS(
            self::XADES_NAMESPACE,
            'xades:SignedSignatureProperties',
        );
        $signedSignatureProperties->appendChild($document->createElementNS(
            self::XADES_NAMESPACE,
            'xades:SigningTime',
            CarbonImmutable::now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z'),
        ));

        $signingCertificate = $document->createElementNS(
            self::XADES_NAMESPACE,
            'xades:SigningCertificate',
        );
        $cert = $document->createElementNS(self::XADES_NAMESPACE, 'xades:Cert');
        $certDigest = $document->createElementNS(self::XADES_NAMESPACE, 'xades:CertDigest');
        $digestMethod = $this->ds($document, 'DigestMethod');
        $digestMethod->setAttribute('Algorithm', self::SHA256_DIGEST);
        $certDigest->appendChild($digestMethod);
        $certDigest->appendChild($this->ds(
            $document,
            'DigestValue',
            base64_encode(hash('sha256', $certificateDer, true)),
        ));
        $cert->appendChild($certDigest);

        $issuerSerial = $document->createElementNS(self::XADES_NAMESPACE, 'xades:IssuerSerial');
        $issuerSerial->appendChild($this->ds(
            $document,
            'X509IssuerName',
            $this->issuerName($certificatePem),
        ));
        $issuerSerial->appendChild($this->ds(
            $document,
            'X509SerialNumber',
            $this->serialNumber($certificateData),
        ));
        $cert->appendChild($issuerSerial);
        $signingCertificate->appendChild($cert);
        $signedSignatureProperties->appendChild($signingCertificate);
        $signedProperties->appendChild($signedSignatureProperties);
        $qualifying->appendChild($signedProperties);
        $object->appendChild($qualifying);

        return [$object, $signedProperties];
    }

    private function signatureMethod(array $details): array
    {
        if (($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA && ($details['bits'] ?? null) === 2048) {
            return [self::RSA_SHA256, false];
        }

        $curve = is_array($details['ec'] ?? null) ? ($details['ec']['curve_name'] ?? null) : null;

        if (($details['type'] ?? null) === OPENSSL_KEYTYPE_EC
            && is_string($curve)
            && in_array(strtolower($curve), ['prime256v1', 'secp256r1'], true)) {
            return [self::ECDSA_SHA256, true];
        }

        $this->fail();
    }

    private function certificateDer(string $certificatePem): string
    {
        $base64 = preg_replace(
            '/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/',
            '',
            $certificatePem,
        );
        $der = base64_decode((string) $base64, true);

        if (! is_string($der)) {
            $this->fail();
        }

        return $der;
    }

    private function issuerName(string $certificatePem): string
    {
        $x509 = new X509;

        if ($x509->loadX509($certificatePem) === false) {
            $this->fail();
        }

        $issuer = $x509->getIssuerDN(X509::DN_ARRAY);

        if (! is_array($issuer) || ! is_array($issuer['rdnSequence'] ?? null)) {
            $this->fail();
        }

        $parts = [];

        foreach ($issuer['rdnSequence'] as $relativeName) {
            if (! is_array($relativeName)) {
                $this->fail();
            }

            $attributes = [];

            foreach ($relativeName as $attribute) {
                if (! is_array($attribute)) {
                    $this->fail();
                }

                $type = $this->issuerAttributeName($attribute['type'] ?? null);
                $value = $this->asn1String($attribute['value'] ?? null);

                if ($type === null || $value === null) {
                    $this->fail();
                }

                $attributes[] = $type.'='.$this->escapeDistinguishedNameValue($value);
            }

            $parts[] = implode('+', $attributes);
        }

        return implode(', ', $parts);
    }

    private function issuerAttributeName(mixed $type): ?string
    {
        if (! is_string($type) || $type === '') {
            return null;
        }

        return match ($type) {
            'id-at-countryName' => 'C',
            'id-at-stateOrProvinceName' => 'ST',
            'id-at-localityName' => 'L',
            'id-at-organizationName' => 'O',
            'id-at-organizationalUnitName' => 'OU',
            'id-at-commonName' => 'CN',
            'id-at-serialNumber' => 'SERIALNUMBER',
            'id-at-surname' => 'SN',
            'id-at-givenName' => 'GN',
            'id-at-emailAddress' => 'E',
            default => preg_match('/^\d+(?:\.\d+)+$/', $type) === 1 ? $type : $type,
        };
    }

    private function asn1String(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof Element) {
            return strtoupper(bin2hex($value->element));
        }

        if (! is_array($value) || count($value) !== 1) {
            return null;
        }

        return $this->asn1String(array_values($value)[0]);
    }

    private function escapeDistinguishedNameValue(string $value): string
    {
        $value = preg_replace('/([,\+"\\<>;=])/', '\\\\$1', $value) ?? $value;

        if (str_starts_with($value, ' ') || str_starts_with($value, '#')) {
            $value = '\\'.$value;
        }

        if (str_ends_with($value, ' ')) {
            $value = substr($value, 0, -1).'\\ ';
        }

        return $value;
    }

    private function serialNumber(array $certificateData): string
    {
        $hex = $certificateData['serialNumberHex'] ?? null;

        if (! is_string($hex) || preg_match('/^[0-9A-Fa-f]+$/', $hex) !== 1) {
            $this->fail();
        }

        return (new BigInteger($hex, 16))->toString();
    }

    private function canonicalRootWithoutSignature(DOMDocument $document): string
    {
        $copy = $document->cloneNode(true);

        if (! $copy instanceof DOMDocument) {
            $this->fail();
        }

        $xpath = new DOMXPath($copy);
        $xpath->registerNamespace('ds', self::DS_NAMESPACE);
        $signatures = $xpath->query('//ds:Signature');

        if ($signatures === false) {
            $this->fail();
        }

        for ($index = $signatures->length - 1; $index >= 0; $index--) {
            $node = $signatures->item($index);
            $node?->parentNode?->removeChild($node);
        }

        $root = $copy->documentElement;

        if (! $root instanceof DOMElement) {
            $this->fail();
        }

        return $this->canonicalize($root);
    }

    private function setReferenceDigest(
        DOMDocument $document,
        mixed $reference,
        string $digest,
    ): void {
        if (! $reference instanceof DOMElement) {
            $this->fail();
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', self::DS_NAMESPACE);
        $node = $xpath->query('./ds:DigestValue', $reference)?->item(0);

        if (! $node instanceof DOMElement) {
            $this->fail();
        }

        $node->nodeValue = $digest;
    }

    private function canonicalize(DOMElement $element): string
    {
        $canonical = $element->C14N(true, false);

        if (! is_string($canonical)) {
            $this->fail();
        }

        return $canonical;
    }

    private function digest(string $data): string
    {
        return base64_encode(hash('sha256', $data, true));
    }

    private function ds(DOMDocument $document, string $name, ?string $value = null): DOMElement
    {
        return $document->createElementNS(self::DS_NAMESPACE, 'ds:'.$name, $value ?? '');
    }

    private function fail(): never
    {
        throw new KsefApiException(
            'Nie udało się podpisać żądania certyfikatem KSeF.',
            'xades_signing_failed',
        );
    }
}
