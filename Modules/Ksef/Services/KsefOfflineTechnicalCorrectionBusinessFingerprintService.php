<?php

namespace Modules\Ksef\Services;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use JsonException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\Fa3\KsefFa3InvoiceMapper;
use Modules\Ksef\Services\Fa3\KsefFa3XmlBuilder;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3DocumentData;

class KsefOfflineTechnicalCorrectionBusinessFingerprintService
{
    public const CURRENT_VERSION = 1;

    public function __construct(
        private readonly KsefFa3InvoiceMapper $mapper,
        private readonly InvoiceDecimalCalculator $decimal,
    ) {}

    public function supportsVersion(int $version): bool
    {
        return $version === 1;
    }

    public function fromInvoice(Invoice $invoice, int $version): string
    {
        $this->assertSupportedVersion($version);

        return $this->fingerprint($this->fromDocumentDataV1(
            $this->mapper->map($invoice, new DateTimeImmutable('@0')),
        ));
    }

    public function fromPayload(string $xml, int $version): string
    {
        $this->assertSupportedVersion($version);

        return $this->fingerprint($this->fromPayloadV1($xml));
    }

    /** @return array<string, mixed> */
    private function fromDocumentDataV1(KsefFa3DocumentData $data): array
    {
        $sellerAddress = $this->dataAddress($data->seller['address'] ?? null, true);
        $buyerAddress = $this->dataAddress($data->buyer['address'] ?? null, false);
        $buyerContacts = $this->dataContacts($data->buyer['contacts'] ?? null);
        $buyerIdentityType = $this->requiredDataString($data->buyer['identity_type'] ?? null);

        return [
            'header' => [
                'form_code' => 'FA',
                'system_code' => 'FA (3)',
                'schema_version' => '1-0E',
                'variant' => '3',
                'system_info' => 'NEX-OMS',
            ],
            'document_kind' => 'VAT',
            'seller' => [
                'taxpayer_prefix' => $this->optionalDataString($data->seller['taxpayer_prefix'] ?? null),
                'nip' => $this->requiredDataString($data->seller['nip'] ?? null),
                'name' => $this->requiredDataString($data->seller['name'] ?? null),
                'address' => $sellerAddress,
            ],
            'buyer' => [
                'identity_type' => $buyerIdentityType,
                'identity_country_code' => $buyerIdentityType === 'eu_vat'
                    ? $this->requiredDataString($data->buyer['identity_country_code'] ?? null)
                    : null,
                'identity_identifier' => $buyerIdentityType === 'none'
                    ? null
                    : $this->requiredDataString($data->buyer['identity_identifier'] ?? null),
                'name' => $this->optionalDataString($data->buyer['name'] ?? null),
                'address' => $buyerAddress,
                'contacts' => $buyerContacts,
                'jst' => $this->dataBool($data->buyer['jst'] ?? null),
                'vat_group' => $this->dataBool($data->buyer['vat_group'] ?? null),
            ],
            'recipient' => $this->dataRecipient($data->recipient),
            'invoice' => [
                'currency' => $this->requiredDataString($data->invoice['currency'] ?? null),
                'issue_date' => $this->requiredDataString($data->invoice['issue_date'] ?? null),
                'place_of_issue' => $this->optionalDataString($data->invoice['place_of_issue'] ?? null),
                'number' => $this->requiredDataString($data->invoice['number'] ?? null),
                'sale_date' => $this->optionalDataString($data->invoice['sale_date'] ?? null),
                'tax_buckets' => $this->dataTaxBuckets($data->taxBuckets),
                'total_gross' => $this->money($data->invoice['total_gross'] ?? null),
                'annotations' => [
                    'cash_accounting' => $this->dataBool($data->annotations['cash_accounting'] ?? null),
                    'self_billing' => $this->dataBool($data->annotations['self_billing'] ?? null),
                    'reverse_charge' => $this->dataBool($data->annotations['reverse_charge'] ?? null),
                    'split_payment' => $this->dataBool($data->annotations['split_payment'] ?? null),
                    'exemption' => false,
                    'new_transport_mean' => $this->dataBool($data->annotations['new_transport_mean'] ?? null),
                    'triangular_transaction' => $this->dataBool($data->annotations['triangular_transaction'] ?? null),
                    'margin_scheme' => $this->dataBool($data->annotations['margin_scheme'] ?? null),
                ],
                'additional_descriptions' => array_map(fn (array $description): array => [
                    'key' => $this->requiredDataString($description['key'] ?? null),
                    'value' => $this->requiredDataString($description['value'] ?? null),
                ], $data->additionalDescriptions),
                'lines' => array_map(fn (array $line): array => [
                    'position' => $this->positiveInteger($line['position'] ?? null),
                    'name' => $this->requiredDataString($line['name'] ?? null),
                    'unit_name' => $this->requiredDataString($line['unit_name'] ?? null),
                    'quantity' => $this->quantity($line['quantity'] ?? null),
                    'unit_price_net' => $this->money($line['unit_price_net'] ?? null),
                    'total_net' => $this->money($line['total_net'] ?? null),
                    'fa3_rate' => $this->vatRate($line['fa3_rate'] ?? null),
                    'gtu' => $this->optionalDataString($line['gtu'] ?? null),
                ], $data->lines),
                'payment' => $this->dataPayment($data->payment),
                'transaction_terms' => $this->dataTransactionTerms($data->transactionTerms),
            ],
            'registrations' => [
                'regon' => $this->optionalDataString($data->registrations['regon'] ?? null),
                'bdo' => $this->optionalDataString($data->registrations['bdo'] ?? null),
            ],
        ];
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

    /** @param array<string, mixed>|null $recipient
     * @return array<string, mixed>|null
     */
    private function dataRecipient(?array $recipient): ?array
    {
        if ($recipient === null) {
            return null;
        }

        return [
            'identity_type' => 'none',
            'name' => $this->requiredDataString($recipient['name'] ?? null),
            'address' => $this->dataAddress($recipient['address'] ?? null, true),
            'role_type' => 'other',
            'role_description' => $this->requiredDataString($recipient['role_description'] ?? null),
        ];
    }

    /** @param array<string, mixed>|null $payment
     * @return array<string, mixed>|null
     */
    private function dataPayment(?array $payment): ?array
    {
        if ($payment === null) {
            return null;
        }

        $bank = is_array($payment['bank_account'] ?? null) ? $payment['bank_account'] : null;

        return [
            'paid_date' => $this->optionalDataString($payment['paid_date'] ?? null),
            'due_date' => $this->optionalDataString($payment['due_date'] ?? null),
            'method_code' => $this->optionalDataString($payment['method_code'] ?? null),
            'method_description' => $this->optionalDataString($payment['method_description'] ?? null),
            'bank_account' => $bank === null ? null : [
                'number' => $this->requiredDataString($bank['number'] ?? null),
                'swift' => $this->optionalDataString($bank['swift'] ?? null),
                'name' => $this->optionalDataString($bank['name'] ?? null),
            ],
        ];
    }

    /** @param array<string, string>|null $terms
     * @return array<string, ?string>|null
     */
    private function dataTransactionTerms(?array $terms): ?array
    {
        if ($terms === null) {
            return null;
        }

        return [
            'date' => $this->optionalDataString($terms['date'] ?? null),
            'number' => $this->requiredDataString($terms['number'] ?? null),
        ];
    }

    /** @param array<string, mixed> $buckets
     * @return array<string, mixed>
     */
    private function dataTaxBuckets(array $buckets): array
    {
        $result = [];
        foreach (['standard_1', 'standard_2', 'standard_3'] as $key) {
            $bucket = $buckets[$key] ?? null;
            $result[$key] = is_array($bucket) ? [
                'net' => $this->money($bucket['net'] ?? null),
                'vat' => $this->money($bucket['vat'] ?? null),
                'pln_vat' => array_key_exists('pln_vat', $bucket)
                    ? $this->money($bucket['pln_vat'])
                    : null,
            ] : null;
        }
        foreach (['domestic_zero', 'wdt', 'export'] as $key) {
            $bucket = $buckets[$key] ?? null;
            $result[$key] = is_array($bucket) ? [
                'net' => $this->money($bucket['net'] ?? null),
            ] : null;
        }

        return $result;
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

    /** @param array<string, mixed>|null $address
     * @return array<string, ?string>|null
     */
    private function dataAddress(mixed $address, bool $required): ?array
    {
        if (! is_array($address)) {
            if (! $required && $address === null) {
                return null;
            }

            throw $this->invalidProjection();
        }

        return [
            'country_code' => $this->requiredDataString($address['country_code'] ?? null),
            'line_1' => $this->requiredDataString($address['line_1'] ?? null),
            'line_2' => $this->optionalDataString($address['line_2'] ?? null),
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

    /** @return array<string, ?string>|null */
    private function dataContacts(mixed $contacts): ?array
    {
        if ($contacts === null) {
            return null;
        }
        if (! is_array($contacts)) {
            throw $this->invalidProjection();
        }

        return [
            'email' => $this->optionalDataString($contacts['email'] ?? null),
            'phone' => $this->optionalDataString($contacts['phone'] ?? null),
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
            || $document->documentElement?->namespaceURI !== KsefFa3XmlBuilder::NAMESPACE) {
            throw $this->invalidProjection();
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fa', KsefFa3XmlBuilder::NAMESPACE);

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
            $normalized = $this->decimal->normalize($value, $scale);
        } catch (\Throwable) {
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

    private function requiredDataString(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw $this->invalidProjection();
        }

        return $value;
    }

    private function optionalDataString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->requiredDataString($value);
    }

    private function dataBool(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw $this->invalidProjection();
        }

        return $value;
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
