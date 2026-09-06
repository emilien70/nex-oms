<?php

namespace Modules\Ksef\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use JsonException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Exceptions\KsefApiException;

class KsefOfflineTechnicalCorrectionBusinessFingerprintService
{
    public const CURRENT_VERSION = 1;

    private const FA3_NAMESPACE = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

    public function __construct(
        private readonly KsefOfflineTechnicalCorrectionInvoiceBusinessProjectionV1 $invoiceProjection,
    ) {}

    public function supportsVersion(int $version): bool
    {
        return $version === 1;
    }

    public function fromInvoice(Invoice $invoice, int $version): string
    {
        $this->assertSupportedVersion($version);

        return $this->fingerprint($this->invoiceProjection->project($invoice));
    }

    public function fromPayload(string $xml, int $version): string
    {
        $this->assertSupportedVersion($version);

        return $this->fingerprint($this->fromPayloadV1($xml));
    }

    /** @return array<string, mixed> */
    private function fromPayloadV1(string $xml): array
    {
        $xpath = $this->xpath($xml);
        $seller = $this->requiredNode($xpath, '/fa:Faktura/fa:Podmiot1');
        $buyer = $this->requiredNode($xpath, '/fa:Faktura/fa:Podmiot2');
        $invoice = $this->requiredNode($xpath, '/fa:Faktura/fa:Fa');
        $formCode = $this->requiredNode($xpath, '/fa:Faktura/fa:Naglowek/fa:KodFormularza');

        return [
            'header' => [
                'form_code' => $this->nodeText($formCode),
                'system_code' => $this->requiredAttribute($formCode, 'kodSystemowy'),
                'schema_version' => $this->requiredAttribute($formCode, 'wersjaSchemy'),
                'variant' => $this->requiredText($xpath, './fa:WariantFormularza', $formCode->parentNode),
                'system_info' => $this->requiredText($xpath, './fa:SystemInfo', $formCode->parentNode),
            ],
            'document_kind' => $this->requiredText($xpath, './fa:RodzajFaktury', $invoice),
            'seller' => [
                'taxpayer_prefix' => $this->optionalText($xpath, './fa:PrefiksPodatnika', $seller),
                'nip' => $this->requiredText($xpath, './fa:DaneIdentyfikacyjne/fa:NIP', $seller),
                'name' => $this->requiredText($xpath, './fa:DaneIdentyfikacyjne/fa:Nazwa', $seller),
                'address' => $this->payloadAddress($xpath, $this->requiredNode($xpath, './fa:Adres', $seller)),
            ],
            'buyer' => $this->payloadBuyer($xpath, $buyer),
            'recipient' => $this->payloadRecipient($xpath),
            'invoice' => [
                'currency' => $this->requiredText($xpath, './fa:KodWaluty', $invoice),
                'issue_date' => $this->requiredText($xpath, './fa:P_1', $invoice),
                'place_of_issue' => $this->optionalText($xpath, './fa:P_1M', $invoice),
                'number' => $this->requiredText($xpath, './fa:P_2', $invoice),
                'sale_date' => $this->optionalText($xpath, './fa:P_6', $invoice),
                'tax_buckets' => $this->payloadTaxBuckets($xpath, $invoice),
                'total_gross' => $this->money($this->requiredText($xpath, './fa:P_15', $invoice)),
                'annotations' => $this->payloadAnnotations($xpath, $invoice),
                'additional_descriptions' => $this->payloadAdditionalDescriptions($xpath, $invoice),
                'lines' => $this->payloadLines($xpath, $invoice),
                'payment' => $this->payloadPayment($xpath, $invoice),
                'transaction_terms' => $this->payloadTransactionTerms($xpath, $invoice),
            ],
            'registrations' => $this->payloadRegistrations($xpath),
        ];
    }

    /** @return array<string, mixed> */
    private function payloadBuyer(DOMXPath $xpath, DOMNode $buyer): array
    {
        $identity = $this->requiredNode($xpath, './fa:DaneIdentyfikacyjne', $buyer);
        $nip = $this->optionalText($xpath, './fa:NIP', $identity);
        $country = $this->optionalText($xpath, './fa:KodUE', $identity);
        $euVat = $this->optionalText($xpath, './fa:NrVatUE', $identity);
        $noId = $this->optionalText($xpath, './fa:BrakID', $identity);

        if ($nip !== null && $country === null && $euVat === null && $noId === null) {
            $identityType = 'pl_nip';
            $identityIdentifier = $nip;
            $identityCountry = null;
        } elseif ($nip === null && $country !== null && $euVat !== null && $noId === null) {
            $identityType = 'eu_vat';
            $identityIdentifier = $euVat;
            $identityCountry = $country;
        } elseif ($nip === null && $country === null && $euVat === null && $noId === '1') {
            $identityType = 'none';
            $identityIdentifier = null;
            $identityCountry = null;
        } else {
            throw $this->invalidProjection();
        }

        $address = $this->optionalNode($xpath, './fa:Adres', $buyer);
        $contacts = $this->optionalNode($xpath, './fa:DaneKontaktowe', $buyer);

        return [
            'identity_type' => $identityType,
            'identity_country_code' => $identityCountry,
            'identity_identifier' => $identityIdentifier,
            'name' => $this->optionalText($xpath, './fa:Nazwa', $identity),
            'address' => $address === null ? null : $this->payloadAddress($xpath, $address),
            'contacts' => $contacts === null ? null : [
                'email' => $this->optionalText($xpath, './fa:Email', $contacts),
                'phone' => $this->optionalText($xpath, './fa:Telefon', $contacts),
            ],
            'jst' => $this->indicator($this->requiredText($xpath, './fa:JST', $buyer)),
            'vat_group' => $this->indicator($this->requiredText($xpath, './fa:GV', $buyer)),
        ];
    }

    /** @return array<string, mixed>|null */
    private function payloadRecipient(DOMXPath $xpath): ?array
    {
        $recipient = $this->optionalNode($xpath, '/fa:Faktura/fa:Podmiot3');
        if ($recipient === null) {
            return null;
        }

        $identity = $this->requiredNode($xpath, './fa:DaneIdentyfikacyjne', $recipient);
        if ($this->requiredText($xpath, './fa:BrakID', $identity) !== '1'
            || $this->requiredText($xpath, './fa:RolaInna', $recipient) !== '1') {
            throw $this->invalidProjection();
        }

        return [
            'identity_type' => 'none',
            'name' => $this->requiredText($xpath, './fa:Nazwa', $identity),
            'address' => $this->payloadAddress($xpath, $this->requiredNode($xpath, './fa:Adres', $recipient)),
            'role_type' => 'other',
            'role_description' => $this->requiredText($xpath, './fa:OpisRoli', $recipient),
        ];
    }

    /** @return array<string, mixed> */
    private function payloadTaxBuckets(DOMXPath $xpath, DOMNode $invoice): array
    {
        $result = [];
        foreach ([
            'standard_1' => ['P_13_1', 'P_14_1', 'P_14_1W'],
            'standard_2' => ['P_13_2', 'P_14_2', 'P_14_2W'],
            'standard_3' => ['P_13_3', 'P_14_3', 'P_14_3W'],
        ] as $key => [$netName, $vatName, $plnVatName]) {
            $net = $this->optionalText($xpath, './fa:'.$netName, $invoice);
            $vat = $this->optionalText($xpath, './fa:'.$vatName, $invoice);
            $plnVat = $this->optionalText($xpath, './fa:'.$plnVatName, $invoice);
            if (($net === null) !== ($vat === null) || ($net === null && $plnVat !== null)) {
                throw $this->invalidProjection();
            }
            $result[$key] = $net === null ? null : [
                'net' => $this->money($net),
                'vat' => $this->money($vat),
                'pln_vat' => $plnVat === null ? null : $this->money($plnVat),
            ];
        }
        foreach ([
            'domestic_zero' => 'P_13_6_1',
            'wdt' => 'P_13_6_2',
            'export' => 'P_13_6_3',
        ] as $key => $element) {
            $net = $this->optionalText($xpath, './fa:'.$element, $invoice);
            $result[$key] = $net === null ? null : ['net' => $this->money($net)];
        }

        return $result;
    }

    /** @return array<string, bool> */
    private function payloadAnnotations(DOMXPath $xpath, DOMNode $invoice): array
    {
        $annotations = $this->requiredNode($xpath, './fa:Adnotacje', $invoice);

        if ($this->requiredText($xpath, './fa:Zwolnienie/fa:P_19N', $annotations) !== '1'
            || $this->requiredText($xpath, './fa:NoweSrodkiTransportu/fa:P_22N', $annotations) !== '1'
            || $this->requiredText($xpath, './fa:PMarzy/fa:P_PMarzyN', $annotations) !== '1') {
            throw $this->invalidProjection();
        }

        return [
            'cash_accounting' => $this->indicator($this->requiredText($xpath, './fa:P_16', $annotations)),
            'self_billing' => $this->indicator($this->requiredText($xpath, './fa:P_17', $annotations)),
            'reverse_charge' => $this->indicator($this->requiredText($xpath, './fa:P_18', $annotations)),
            'split_payment' => $this->indicator($this->requiredText($xpath, './fa:P_18A', $annotations)),
            'exemption' => false,
            'new_transport_mean' => false,
            'triangular_transaction' => $this->indicator($this->requiredText($xpath, './fa:P_23', $annotations)),
            'margin_scheme' => false,
        ];
    }

    /** @return array<int, array<string, string>> */
    private function payloadAdditionalDescriptions(DOMXPath $xpath, DOMNode $invoice): array
    {
        $result = [];
        foreach ($this->nodes($xpath, './fa:DodatkowyOpis', $invoice) as $description) {
            $result[] = [
                'key' => $this->requiredText($xpath, './fa:Klucz', $description),
                'value' => $this->requiredText($xpath, './fa:Wartosc', $description),
            ];
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function payloadLines(DOMXPath $xpath, DOMNode $invoice): array
    {
        $result = [];
        foreach ($this->nodes($xpath, './fa:FaWiersz', $invoice) as $line) {
            $result[] = [
                'position' => $this->positiveInteger($this->requiredText($xpath, './fa:NrWierszaFa', $line)),
                'name' => $this->requiredText($xpath, './fa:P_7', $line),
                'unit_name' => $this->requiredText($xpath, './fa:P_8A', $line),
                'quantity' => $this->quantity($this->requiredText($xpath, './fa:P_8B', $line)),
                'unit_price_net' => $this->money($this->requiredText($xpath, './fa:P_9A', $line)),
                'total_net' => $this->money($this->requiredText($xpath, './fa:P_11', $line)),
                'fa3_rate' => $this->vatRate($this->requiredText($xpath, './fa:P_12', $line)),
                'gtu' => $this->optionalText($xpath, './fa:GTU', $line),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function payloadPayment(DOMXPath $xpath, DOMNode $invoice): ?array
    {
        $payment = $this->optionalNode($xpath, './fa:Platnosc', $invoice);
        if ($payment === null) {
            return null;
        }

        $paid = $this->optionalText($xpath, './fa:Zaplacono', $payment);
        $paidDate = $this->optionalText($xpath, './fa:DataZaplaty', $payment);
        if (($paid === null) !== ($paidDate === null) || ($paid !== null && $paid !== '1')) {
            throw $this->invalidProjection();
        }

        $methodCode = $this->optionalText($xpath, './fa:FormaPlatnosci', $payment);
        $other = $this->optionalText($xpath, './fa:PlatnoscInna', $payment);
        $description = $this->optionalText($xpath, './fa:OpisPlatnosci', $payment);
        if ($methodCode !== null && ($other !== null || $description !== null)) {
            throw $this->invalidProjection();
        }
        if ($methodCode === null && (($other === null) !== ($description === null))) {
            throw $this->invalidProjection();
        }
        if ($other !== null && $other !== '1') {
            throw $this->invalidProjection();
        }

        $bank = $this->optionalNode($xpath, './fa:RachunekBankowy', $payment);

        return [
            'paid_date' => $paidDate,
            'due_date' => $this->optionalText($xpath, './fa:TerminPlatnosci/fa:Termin', $payment),
            'method_code' => $methodCode,
            'method_description' => $description,
            'bank_account' => $bank === null ? null : [
                'number' => $this->requiredText($xpath, './fa:NrRB', $bank),
                'swift' => $this->optionalText($xpath, './fa:SWIFT', $bank),
                'name' => $this->optionalText($xpath, './fa:NazwaBanku', $bank),
            ],
        ];
    }

    /** @return array<string, ?string>|null */
    private function payloadTransactionTerms(DOMXPath $xpath, DOMNode $invoice): ?array
    {
        $terms = $this->optionalNode($xpath, './fa:WarunkiTransakcji', $invoice);
        if ($terms === null) {
            return null;
        }

        $order = $this->requiredNode($xpath, './fa:Zamowienia', $terms);

        return [
            'date' => $this->optionalText($xpath, './fa:DataZamowienia', $order),
            'number' => $this->requiredText($xpath, './fa:NrZamowienia', $order),
        ];
    }

    /** @return array<string, ?string> */
    private function payloadRegistrations(DOMXPath $xpath): array
    {
        $registrations = $this->optionalNode($xpath, '/fa:Faktura/fa:Stopka/fa:Rejestry');

        return [
            'regon' => $registrations === null ? null : $this->optionalText($xpath, './fa:REGON', $registrations),
            'bdo' => $registrations === null ? null : $this->optionalText($xpath, './fa:BDO', $registrations),
        ];
    }

    /** @return array<string, ?string> */
    private function payloadAddress(DOMXPath $xpath, DOMNode $address): array
    {
        return [
            'country_code' => $this->requiredText($xpath, './fa:KodKraju', $address),
            'line_1' => $this->requiredText($xpath, './fa:AdresL1', $address),
            'line_2' => $this->optionalText($xpath, './fa:AdresL2', $address),
        ];
    }

    private function xpath(string $xml): DOMXPath
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw $this->invalidProjection();
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
            || $document->documentElement?->namespaceURI !== self::FA3_NAMESPACE) {
            throw $this->invalidProjection();
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fa', self::FA3_NAMESPACE);

        return $xpath;
    }

    private function requiredNode(
        DOMXPath $xpath,
        string $expression,
        ?DOMNode $context = null,
    ): DOMElement {
        $nodes = $this->nodes($xpath, $expression, $context);
        if ($nodes->length !== 1 || ! $nodes->item(0) instanceof DOMElement) {
            throw $this->invalidProjection();
        }

        return $nodes->item(0);
    }

    private function optionalNode(
        DOMXPath $xpath,
        string $expression,
        ?DOMNode $context = null,
    ): ?DOMElement {
        $nodes = $this->nodes($xpath, $expression, $context);
        if ($nodes->length > 1 || ($nodes->length === 1 && ! $nodes->item(0) instanceof DOMElement)) {
            throw $this->invalidProjection();
        }

        return $nodes->length === 1 ? $nodes->item(0) : null;
    }

    private function requiredText(
        DOMXPath $xpath,
        string $expression,
        ?DOMNode $context = null,
    ): string {
        return $this->nodeText($this->requiredNode($xpath, $expression, $context));
    }

    private function optionalText(
        DOMXPath $xpath,
        string $expression,
        ?DOMNode $context = null,
    ): ?string {
        $node = $this->optionalNode($xpath, $expression, $context);

        return $node === null ? null : $this->nodeText($node);
    }

    private function nodeText(DOMNode $node): string
    {
        $value = $node->textContent;
        if ($value === '') {
            throw $this->invalidProjection();
        }

        return $value;
    }

    private function requiredAttribute(DOMElement $node, string $name): string
    {
        $value = $node->getAttribute($name);
        if ($value === '') {
            throw $this->invalidProjection();
        }

        return $value;
    }

    private function nodes(DOMXPath $xpath, string $expression, ?DOMNode $context = null): \DOMNodeList
    {
        $nodes = $xpath->query($expression, $context);
        if ($nodes === false) {
            throw $this->invalidProjection();
        }

        return $nodes;
    }

    private function money(mixed $value): string
    {
        return $this->decimal($value, 2, false);
    }

    private function quantity(mixed $value): string
    {
        return $this->decimal($value, 4, true);
    }

    private function vatRate(mixed $value): string
    {
        if (is_string($value) && in_array($value, ['0 KR', '0 WDT', '0 EX'], true)) {
            return $value;
        }

        return $this->decimal($value, 2, true);
    }

    private function decimal(mixed $value, int $scale, bool $trimZeros): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw $this->invalidProjection();
        }

        $value = trim((string) $value);
        if (preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d{1,'.$scale.'})?$/', $value) !== 1) {
            throw $this->invalidProjection();
        }

        try {
            $normalized = BigDecimal::of($value)
                ->toScale($scale, RoundingMode::HALF_UP)
                ->__toString();
        } catch (MathException|InvalidArgumentException) {
            throw $this->invalidProjection();
        }

        if (! $trimZeros) {
            return $normalized;
        }

        $normalized = rtrim(rtrim($normalized, '0'), '.');

        return $normalized === '-0' ? '0' : $normalized;
    }

    private function positiveInteger(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw $this->invalidProjection();
        }

        $value = trim((string) $value);
        if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
            throw $this->invalidProjection();
        }

        return ltrim($value, '0');
    }

    private function indicator(string $value): bool
    {
        return match ($value) {
            '1' => true,
            '2' => false,
            default => throw $this->invalidProjection(),
        };
    }

    /** @param array<string, mixed> $projection */
    private function fingerprint(array $projection): string
    {
        try {
            $canonical = json_encode(
                $projection,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw $this->invalidProjection();
        }

        return base64_encode(hash('sha256', $canonical, true));
    }

    private function assertSupportedVersion(int $version): void
    {
        if (! $this->supportsVersion($version)) {
            throw new KsefApiException(
                'Wersja biznesowego fingerprintu korekty technicznej nie jest obsługiwana.',
                'ksef_technical_correction_business_fingerprint_version_unsupported',
            );
        }
    }

    private function invalidProjection(): KsefApiException
    {
        return new KsefApiException(
            'Treść biznesowa korekty technicznej jest niekompletna lub niespójna.',
            'ksef_technical_correction_business_projection_invalid',
        );
    }
}
