<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefOfflineBuyerClassification;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Services\Fa3\KsefFa3XmlBuilder;
use Modules\Ksef\ValueObjects\KsefOfflinePresentationData;

final class KsefOfflinePresentationDataExtractor
{
    private const SCHEMA_ID = 'FA (3) 1-0E';

    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly KsefFa3BuyerIdentityResolver $buyerIdentities,
        private readonly KsefQrEnvironmentHostPolicy $qrHosts,
    ) {}

    public function extract(KsefOfflineIssuance $issuance): KsefOfflinePresentationData
    {
        $xml = $issuance->payload_xml;

        if (! in_array($issuance->procedure, KsefOfflineIssuanceProcedure::cases(), true)
            || ! in_array($issuance->environment, [KsefEnvironment::Test, KsefEnvironment::Demo], true)
            || $issuance->schema_id !== self::SCHEMA_ID
            || ! is_string($xml)
            || $xml === ''
            || strlen($xml) !== $issuance->invoice_size
            || ! hash_equals((string) $issuance->invoice_hash, base64_encode(hash('sha256', $xml, true)))) {
            throw $this->integrityInvalid();
        }

        $xpath = $this->xpath($xml);
        $this->assertSchema($xpath);

        $sellerNip = $this->required($xpath, '/fa:Faktura/fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:NIP');
        $issueDate = $this->date($this->required($xpath, '/fa:Faktura/fa:Fa/fa:P_1'));

        if (! hash_equals((string) $issuance->seller_nip, $sellerNip)
            || $issuance->issue_date?->toDateString() !== $issueDate) {
            throw $this->integrityInvalid();
        }

        if (! $this->invoiceUrlIsValid($issuance, $sellerNip, $issueDate)
            || ! $this->certificateUrlIsValid($issuance)) {
            throw $this->integrityInvalid();
        }

        $seller = $this->seller($xpath, $sellerNip);
        [$buyer, $classification] = $this->buyer($xpath);
        [$lines, $lineTaxTreatments] = $this->lines($xpath);
        [$taxRows, $totalNet, $totalVat] = $this->taxRows($xpath, $lineTaxTreatments);

        return new KsefOfflinePresentationData(
            environment: $issuance->environment,
            procedure: $issuance->procedure,
            buyerClassification: $classification,
            seller: $seller,
            buyer: $buyer,
            invoiceNumber: $this->required($xpath, '/fa:Faktura/fa:Fa/fa:P_2'),
            issueDate: $issueDate,
            saleDate: $this->optional($xpath, '/fa:Faktura/fa:Fa/fa:P_6', date: true),
            placeOfIssue: $this->optional($xpath, '/fa:Faktura/fa:Fa/fa:P_1M'),
            currency: $this->currency($this->required($xpath, '/fa:Faktura/fa:Fa/fa:KodWaluty')),
            totalNet: $totalNet,
            totalVat: $totalVat,
            totalGross: $this->money($this->required($xpath, '/fa:Faktura/fa:Fa/fa:P_15')),
            lines: $lines,
            taxRows: $taxRows,
            additionalDescriptions: $this->additionalDescriptions($xpath),
            payment: $this->payment($xpath),
            orderNumber: $this->optional($xpath, '/fa:Faktura/fa:Fa/fa:WarunkiTransakcji/fa:Zamowienia/fa:NrZamowienia'),
            invoiceVerificationUrl: (string) $issuance->invoice_verification_url,
            certificateVerificationUrl: (string) $issuance->certificate_verification_url,
        );
    }

    private function xpath(string $xml): DOMXPath
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw $this->integrityInvalid();
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
            throw $this->integrityInvalid();
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fa', KsefFa3XmlBuilder::NAMESPACE);

        return $xpath;
    }

    private function assertSchema(DOMXPath $xpath): void
    {
        $nodes = $this->nodes($xpath, '/fa:Faktura/fa:Naglowek/fa:KodFormularza');
        $node = $nodes->item(0);

        if ($nodes->length !== 1
            || ! $node instanceof DOMElement
            || trim($node->textContent) !== 'FA'
            || $node->getAttribute('kodSystemowy') !== 'FA (3)'
            || $node->getAttribute('wersjaSchemy') !== '1-0E') {
            throw $this->integrityInvalid();
        }
    }

    /** @return array{name: string, nip: string, address: list<string>} */
    private function seller(DOMXPath $xpath, string $nip): array
    {
        return [
            'name' => $this->required($xpath, '/fa:Faktura/fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:Nazwa'),
            'nip' => $nip,
            'address' => $this->address($xpath, '/fa:Faktura/fa:Podmiot1'),
        ];
    }

    /**
     * @return array{
     *     0: array{name: string, identity_label: string, identity_value: ?string, address: list<string>},
     *     1: KsefOfflineBuyerClassification
     * }
     */
    private function buyer(DOMXPath $xpath): array
    {
        $base = '/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne';
        $nip = $this->optionalIdentity($xpath, $base.'/fa:NIP');
        $euCode = $this->optionalIdentity($xpath, $base.'/fa:KodUE');
        $euNumber = $this->optionalIdentity($xpath, $base.'/fa:NrVatUE');
        $noId = $this->optionalIdentity($xpath, $base.'/fa:BrakID');

        if ($nip !== null && $euCode === null && $euNumber === null && $noId === null) {
            $normalized = $this->buyerIdentities->normalizePolishNip($nip);
            if ($normalized === null || ! hash_equals($normalized, $nip)) {
                throw $this->buyerIdentityInvalid();
            }

            $classification = KsefOfflineBuyerClassification::DomesticPlNip;
            $identityLabel = 'NIP';
            $identityValue = $nip;
        } elseif ($nip === null && $euCode !== null && $euNumber !== null && $noId === null) {
            $resolved = $this->buyerIdentities->resolve([
                'country_code' => $euCode,
                'tax_id' => $euCode.$euNumber,
            ]);

            if (($resolved['status'] ?? null) !== 'resolved' || ($resolved['type'] ?? null) !== 'eu_vat') {
                throw $this->buyerIdentityInvalid();
            }

            $classification = KsefOfflineBuyerClassification::NoPlNip;
            $identityLabel = 'VAT UE';
            $identityValue = $euCode.$euNumber;
        } elseif ($nip === null && $euCode === null && $euNumber === null && $noId === '1') {
            $classification = KsefOfflineBuyerClassification::NoPlNip;
            $identityLabel = 'Identyfikator podatkowy';
            $identityValue = null;
        } else {
            throw $this->buyerIdentityInvalid();
        }

        return [[
            'name' => $this->required($xpath, '/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa', buyer: true),
            'identity_label' => $identityLabel,
            'identity_value' => $identityValue,
            'address' => $this->address($xpath, '/fa:Faktura/fa:Podmiot2', buyer: true),
        ], $classification];
    }

    /** @return list<string> */
    private function address(DOMXPath $xpath, string $subjectPath, bool $buyer = false): array
    {
        $country = $this->required($xpath, $subjectPath.'/fa:Adres/fa:KodKraju', buyer: $buyer);
        $line1 = $this->required($xpath, $subjectPath.'/fa:Adres/fa:AdresL1', buyer: $buyer);
        $line2 = $this->optional($xpath, $subjectPath.'/fa:Adres/fa:AdresL2', buyer: $buyer);

        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            throw $buyer ? $this->buyerIdentityInvalid() : $this->integrityInvalid();
        }

        return array_values(array_filter([$line1, $line2, $country], static fn (?string $line): bool => $line !== null));
    }

    /**
     * @return array{
     *     0: list<array{name: string, unit_name: string, quantity: string, unit_price_net: string, total_net: string, vat: string, gtu: ?string}>,
     *     1: list<string>
     * }
     */
    private function lines(DOMXPath $xpath): array
    {
        $nodes = $this->nodes($xpath, '/fa:Faktura/fa:Fa/fa:FaWiersz');
        if ($nodes->length === 0) {
            throw $this->integrityInvalid();
        }

        $lines = [];
        $taxTreatments = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                throw $this->integrityInvalid();
            }

            $taxTreatment = $this->taxTreatment($this->required($xpath, './fa:P_12', $node));
            $lines[] = [
                'name' => $this->required($xpath, './fa:P_7', $node),
                'unit_name' => $this->required($xpath, './fa:P_8A', $node),
                'quantity' => $this->quantity($this->required($xpath, './fa:P_8B', $node)),
                'unit_price_net' => $this->money($this->required($xpath, './fa:P_9A', $node)),
                'total_net' => $this->money($this->required($xpath, './fa:P_11', $node)),
                'vat' => $this->vatLabel($taxTreatment),
                'gtu' => $this->optional($xpath, './fa:GTU', context: $node),
            ];
            $taxTreatments[] = $taxTreatment;
        }

        return [$lines, array_values(array_unique($taxTreatments))];
    }

    /**
     * @param  list<string>  $lineTaxTreatments
     * @return array{0: list<array{vat: string, net: string, vat_amount: string, gross: string}>, 1: string, 2: string}
     */
    private function taxRows(DOMXPath $xpath, array $lineTaxTreatments): array
    {
        $rows = [];
        $totalNet = '0.00';
        $totalVat = '0.00';

        foreach ([
            ['P_13_1', 'P_14_1', ['23', '22']],
            ['P_13_2', 'P_14_2', ['8', '7']],
            ['P_13_3', 'P_14_3', ['5']],
        ] as [$netField, $vatField, $bucketTreatments]) {
            $net = $this->optional($xpath, '/fa:Faktura/fa:Fa/fa:'.$netField);
            $vat = $this->optional($xpath, '/fa:Faktura/fa:Fa/fa:'.$vatField);
            if (($net === null) !== ($vat === null)) {
                throw $this->integrityInvalid();
            }

            $presentTreatments = array_values(array_intersect($bucketTreatments, $lineTaxTreatments));
            if (($net !== null) !== ($presentTreatments !== [])) {
                throw $this->integrityInvalid();
            }
            if ($net === null || $vat === null) {
                continue;
            }

            $net = $this->money($net);
            $vat = $this->money($vat);
            $label = implode(' / ', array_map(static fn (string $rate): string => $rate.'%', $presentTreatments));
            $rows[] = ['vat' => $label, 'net' => $net, 'vat_amount' => $vat, 'gross' => $this->add($net, $vat)];
            $totalNet = $this->add($totalNet, $net);
            $totalVat = $this->add($totalVat, $vat);
        }

        foreach ([
            ['P_13_6_1', '0% krajowa'],
            ['P_13_6_2', '0% WDT'],
            ['P_13_6_3', '0% eksport'],
        ] as [$netField, $label]) {
            $net = $this->optional($xpath, '/fa:Faktura/fa:Fa/fa:'.$netField);
            $treatment = match ($netField) {
                'P_13_6_1' => '0 KR',
                'P_13_6_2' => '0 WDT',
                'P_13_6_3' => '0 EX',
            };
            $hasMatchingLine = in_array($treatment, $lineTaxTreatments, true);

            if (($net !== null) !== $hasMatchingLine) {
                throw $this->integrityInvalid();
            }
            if ($net === null) {
                continue;
            }

            $net = $this->money($net);
            $rows[] = ['vat' => $label, 'net' => $net, 'vat_amount' => '0.00', 'gross' => $net];
            $totalNet = $this->add($totalNet, $net);
        }

        if ($rows === []) {
            throw $this->integrityInvalid();
        }

        return [$rows, $totalNet, $totalVat];
    }

    /** @return list<array{key: string, value: string}> */
    private function additionalDescriptions(DOMXPath $xpath): array
    {
        $descriptions = [];
        foreach ($this->nodes($xpath, '/fa:Faktura/fa:Fa/fa:DodatkowyOpis') as $node) {
            if (! $node instanceof DOMElement) {
                throw $this->integrityInvalid();
            }

            $descriptions[] = [
                'key' => $this->required($xpath, './fa:Klucz', $node),
                'value' => $this->required($xpath, './fa:Wartosc', $node),
            ];
        }

        return $descriptions;
    }

    /** @return array<string, string|bool|null>|null */
    private function payment(DOMXPath $xpath): ?array
    {
        $payments = $this->nodes($xpath, '/fa:Faktura/fa:Fa/fa:Platnosc');
        if ($payments->length === 0) {
            return null;
        }
        if ($payments->length !== 1 || ! $payments->item(0) instanceof DOMElement) {
            throw $this->integrityInvalid();
        }

        $node = $payments->item(0);
        $methodCode = $this->optional($xpath, './fa:FormaPlatnosci', context: $node);
        $other = $this->optional($xpath, './fa:PlatnoscInna', context: $node);
        $description = $this->optional($xpath, './fa:OpisPlatnosci', context: $node);
        $method = null;

        if ($methodCode !== null) {
            $method = [
                '1' => 'Gotówka',
                '2' => 'Karta',
                '3' => 'Bon',
                '4' => 'Czek',
                '5' => 'Kredyt',
                '6' => 'Przelew',
                '7' => 'Mobilna',
            ][$methodCode] ?? null;
            if ($method === null || $other !== null || $description !== null) {
                throw $this->integrityInvalid();
            }
        } elseif ($other === '1' && $description !== null) {
            $method = $description;
        } elseif ($other !== null || $description !== null) {
            throw $this->integrityInvalid();
        }

        return [
            'paid' => $this->optional($xpath, './fa:Zaplacono', context: $node) === '1',
            'paid_date' => $this->optional($xpath, './fa:DataZaplaty', date: true, context: $node),
            'due_date' => $this->optional($xpath, './fa:TerminPlatnosci/fa:Termin', date: true, context: $node),
            'method' => $method,
            'bank_account' => $this->optional($xpath, './fa:RachunekBankowy/fa:NrRB', context: $node),
            'bank_swift' => $this->optional($xpath, './fa:RachunekBankowy/fa:SWIFT', context: $node),
            'bank_name' => $this->optional($xpath, './fa:RachunekBankowy/fa:NazwaBanku', context: $node),
        ];
    }

    private function invoiceUrlIsValid(
        KsefOfflineIssuance $issuance,
        string $sellerNip,
        string $issueDate,
    ): bool {
        $parts = parse_url((string) $issuance->invoice_verification_url);
        $hash = $this->base64Url((string) $issuance->invoice_hash);
        [$year, $month, $day] = explode('-', $issueDate);
        $expectedPath = sprintf('/invoice/%s/%s-%s-%s/%s', $sellerNip, $day, $month, $year, $hash);

        return $this->hasValidUrlEnvelope($parts, $issuance->environment)
            && ($parts['path'] ?? null) === $expectedPath;
    }

    private function certificateUrlIsValid(KsefOfflineIssuance $issuance): bool
    {
        $url = (string) $issuance->certificate_verification_url;
        $parts = parse_url($url);
        $segments = is_array($parts)
            ? explode('/', trim((string) ($parts['path'] ?? ''), '/'))
            : [];
        $hash = $this->base64Url((string) $issuance->invoice_hash);

        return $this->hasValidUrlEnvelope($parts, $issuance->environment)
            && count($segments) === 7
            && ($parts['path'] ?? null) === '/'.implode('/', $segments)
            && $segments[0] === 'certificate'
            && $segments[1] === $issuance->context_identifier_type->value
            && $segments[2] === (string) $issuance->context_identifier_value
            && $segments[3] === (string) $issuance->seller_nip
            && $segments[4] === (string) $issuance->certificate_serial_number
            && $segments[5] === $hash
            && preg_match('/^[A-Za-z0-9_-]+$/D', $segments[6]) === 1
            && $this->base64UrlDecode($segments[6]) !== null;
    }

    /** @param array<string, int|string>|false $parts */
    private function hasValidUrlEnvelope(array|false $parts, KsefEnvironment $environment): bool
    {
        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && $this->qrHosts->allows($environment, $parts['host'])
            && ! isset($parts['port'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    private function base64Url(string $base64): string
    {
        return rtrim(strtr($base64, '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(
            strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4),
            true,
        );

        return is_string($decoded)
            && $decoded !== ''
            && hash_equals(rtrim(strtr(base64_encode($decoded), '+/', '-_'), '='), $value)
                ? $decoded
                : null;
    }

    private function required(
        DOMXPath $xpath,
        string $expression,
        ?DOMNode $context = null,
        bool $buyer = false,
    ): string {
        $nodes = $this->nodes($xpath, $expression, $context);
        $value = $nodes->length === 1 ? trim($nodes->item(0)?->textContent ?? '') : '';

        if ($nodes->length !== 1 || $value === '') {
            throw $buyer ? $this->buyerIdentityInvalid() : $this->integrityInvalid();
        }

        return $value;
    }

    private function optional(
        DOMXPath $xpath,
        string $expression,
        bool $date = false,
        ?DOMNode $context = null,
        bool $buyer = false,
    ): ?string {
        $nodes = $this->nodes($xpath, $expression, $context);
        if ($nodes->length === 0) {
            return null;
        }

        $value = $this->required($xpath, $expression, $context, $buyer);

        return $date ? $this->date($value) : $value;
    }

    private function optionalIdentity(DOMXPath $xpath, string $expression): ?string
    {
        return $this->optional($xpath, $expression, buyer: true);
    }

    private function nodes(DOMXPath $xpath, string $expression, ?DOMNode $context = null): DOMNodeList
    {
        $nodes = $xpath->query($expression, $context);
        if ($nodes === false) {
            throw $this->integrityInvalid();
        }

        return $nodes;
    }

    private function date(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw $this->integrityInvalid();
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Europe/Warsaw');
        $errors = CarbonImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw $this->integrityInvalid();
        }

        return $value;
    }

    private function currency(string $value): string
    {
        if (preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw $this->integrityInvalid();
        }

        return $value;
    }

    private function money(string $value): string
    {
        if (preg_match('/^-?\d+(?:\.\d+)?$/D', $value) !== 1) {
            throw $this->integrityInvalid();
        }

        try {
            return $this->decimal->normalize($value, 2);
        } catch (InvoiceDomainException) {
            throw $this->integrityInvalid();
        }
    }

    private function quantity(string $value): string
    {
        if (preg_match('/^-?\d+(?:\.\d+)?$/D', $value) !== 1) {
            throw $this->integrityInvalid();
        }

        try {
            $normalized = $this->decimal->normalize($value, 4);
        } catch (InvoiceDomainException) {
            throw $this->integrityInvalid();
        }

        return rtrim(rtrim($normalized, '0'), '.');
    }

    private function add(string $left, string $right): string
    {
        try {
            return $this->decimal->add($left, $right);
        } catch (InvoiceDomainException) {
            throw $this->integrityInvalid();
        }
    }

    private function taxTreatment(string $value): string
    {
        if (preg_match('/^(23|22|8|7|5)(?:\.0+)?$/D', $value, $matches) === 1) {
            return $matches[1];
        }

        return match ($value) {
            '0 KR', '0 WDT', '0 EX' => $value,
            default => throw $this->integrityInvalid(),
        };
    }

    private function vatLabel(string $taxTreatment): string
    {
        return match ($taxTreatment) {
            '23', '22', '8', '7', '5' => $taxTreatment.'%',
            '0 KR' => '0% krajowa',
            '0 WDT' => '0% WDT',
            '0 EX' => '0% eksport',
            default => throw $this->integrityInvalid(),
        };
    }

    private function integrityInvalid(): KsefApiException
    {
        return new KsefApiException(
            'Nie można bezpiecznie przedstawić Faktury Offline, ponieważ jej zamrożone dane są niespójne.',
            'ksef_offline_presentation_integrity_invalid',
        );
    }

    private function buyerIdentityInvalid(): KsefApiException
    {
        return new KsefApiException(
            'Nie można jednoznacznie ustalić sposobu wydania dokumentu na podstawie zamrożonych danych nabywcy.',
            'ksef_offline_delivery_buyer_identity_invalid',
        );
    }
}
