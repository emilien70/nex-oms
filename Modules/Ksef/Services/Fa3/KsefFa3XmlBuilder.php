<?php

namespace Modules\Ksef\Services\Fa3;

use DOMDocument;
use DOMElement;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3DocumentData;

class KsefFa3XmlBuilder
{
    public const NAMESPACE = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

    public function build(KsefFa3DocumentData $data): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $document->preserveWhiteSpace = false;

        $root = $document->createElementNS(self::NAMESPACE, 'Faktura');
        $document->appendChild($root);
        $this->header($document, $root, $data);
        $this->seller($document, $root, $data->seller);
        $this->buyer($document, $root, $data->buyer);
        $this->invoice($document, $root, $data);
        $this->footer($document, $root, $data->registrations);

        $xml = $document->saveXML();
        if (! is_string($xml)) {
            throw new InvoiceDomainException(
                'ksef_fa3_xml_build_failed',
                'Nie można utworzyć dokumentu XML FA(3).',
            );
        }

        return $xml;
    }

    private function header(DOMDocument $document, DOMElement $root, KsefFa3DocumentData $data): void
    {
        $header = $this->element($document, $root, 'Naglowek');
        $formCode = $this->element($document, $header, 'KodFormularza', 'FA');
        $formCode->setAttribute('kodSystemowy', 'FA (3)');
        $formCode->setAttribute('wersjaSchemy', '1-0E');
        $this->element($document, $header, 'WariantFormularza', '3');
        $this->element($document, $header, 'DataWytworzeniaFa', $data->generatedAt);
        $this->element($document, $header, 'SystemInfo', 'NEX-OMS');
    }

    /** @param array<string, mixed> $seller */
    private function seller(DOMDocument $document, DOMElement $root, array $seller): void
    {
        $subject = $this->element($document, $root, 'Podmiot1');
        $identity = $this->element($document, $subject, 'DaneIdentyfikacyjne');
        $this->element($document, $identity, 'NIP', (string) $seller['nip']);
        $this->element($document, $identity, 'Nazwa', (string) $seller['name']);
        $this->address($document, $subject, $seller['address']);
    }

    /** @param array<string, mixed> $buyer */
    private function buyer(DOMDocument $document, DOMElement $root, array $buyer): void
    {
        $subject = $this->element($document, $root, 'Podmiot2');
        $identity = $this->element($document, $subject, 'DaneIdentyfikacyjne');

        match ($buyer['identity_type']) {
            'pl_nip' => $this->element($document, $identity, 'NIP', (string) $buyer['identity_identifier']),
            'eu_vat' => $this->euVatIdentity($document, $identity, $buyer),
            'none' => $this->element($document, $identity, 'BrakID', '1'),
            default => throw new InvoiceDomainException(
                'ksef_fa3_buyer_snapshot_invalid',
                'Snapshot nabywcy nie pozwala utworzyć FA(3).',
            ),
        };

        if (is_string($buyer['name'] ?? null)) {
            $this->element($document, $identity, 'Nazwa', $buyer['name']);
        }
        if (is_array($buyer['address'] ?? null)) {
            $this->address($document, $subject, $buyer['address']);
        }
        $this->element($document, $subject, 'JST', ($buyer['jst'] ?? false) ? '1' : '2');
        $this->element($document, $subject, 'GV', ($buyer['vat_group'] ?? false) ? '1' : '2');
    }

    /** @param array<string, mixed> $buyer */
    private function euVatIdentity(DOMDocument $document, DOMElement $identity, array $buyer): DOMElement
    {
        $this->element($document, $identity, 'KodUE', (string) $buyer['identity_country_code']);

        return $this->element($document, $identity, 'NrVatUE', (string) $buyer['identity_identifier']);
    }

    private function invoice(DOMDocument $document, DOMElement $root, KsefFa3DocumentData $data): void
    {
        $invoice = $this->element($document, $root, 'Fa');
        $this->element($document, $invoice, 'KodWaluty', (string) $data->invoice['currency']);
        $this->element($document, $invoice, 'P_1', (string) $data->invoice['issue_date']);
        if (is_string($data->invoice['place_of_issue'])) {
            $this->element($document, $invoice, 'P_1M', $data->invoice['place_of_issue']);
        }
        $this->element($document, $invoice, 'P_2', (string) $data->invoice['number']);
        $this->element($document, $invoice, 'P_6', (string) $data->invoice['sale_date']);
        $this->taxSummary($document, $invoice, $data->taxBuckets);
        $this->element($document, $invoice, 'P_15', (string) $data->invoice['total_gross']);
        $this->annotations($document, $invoice, $data->annotations);
        $this->element($document, $invoice, 'RodzajFaktury', 'VAT');

        foreach ($data->lines as $line) {
            $row = $this->element($document, $invoice, 'FaWiersz');
            $this->element($document, $row, 'NrWierszaFa', (string) $line['position']);
            $this->element($document, $row, 'P_7', (string) $line['name']);
            $this->element($document, $row, 'P_8A', (string) $line['unit_name']);
            $this->element($document, $row, 'P_8B', (string) $line['quantity']);
            $this->element($document, $row, 'P_9A', (string) $line['unit_price_net']);
            $this->element($document, $row, 'P_11', (string) $line['total_net']);
            $this->element($document, $row, 'P_12', (string) $line['fa3_rate']);
        }
    }

    /** @param array<string, array<string, string>|null> $buckets */
    private function taxSummary(DOMDocument $document, DOMElement $invoice, array $buckets): void
    {
        foreach ([
            'standard_1' => ['P_13_1', 'P_14_1', 'P_14_1W'],
            'standard_2' => ['P_13_2', 'P_14_2', 'P_14_2W'],
            'standard_3' => ['P_13_3', 'P_14_3', 'P_14_3W'],
        ] as $key => [$netElement, $vatElement, $plnVatElement]) {
            $bucket = $buckets[$key] ?? null;
            if ($bucket === null) {
                continue;
            }
            $this->element($document, $invoice, $netElement, $bucket['net']);
            $this->element($document, $invoice, $vatElement, $bucket['vat']);
            if (isset($bucket['pln_vat'])) {
                $this->element($document, $invoice, $plnVatElement, $bucket['pln_vat']);
            }
        }

        foreach ([
            'domestic_zero' => 'P_13_6_1',
            'wdt' => 'P_13_6_2',
            'export' => 'P_13_6_3',
        ] as $key => $element) {
            $bucket = $buckets[$key] ?? null;
            if ($bucket !== null) {
                $this->element($document, $invoice, $element, $bucket['net']);
            }
        }
    }

    /** @param array<string, bool> $annotations */
    private function annotations(DOMDocument $document, DOMElement $invoice, array $annotations): void
    {
        $node = $this->element($document, $invoice, 'Adnotacje');
        $this->element($document, $node, 'P_16', $annotations['cash_accounting'] ? '1' : '2');
        $this->element($document, $node, 'P_17', $annotations['self_billing'] ? '1' : '2');
        $this->element($document, $node, 'P_18', $annotations['reverse_charge'] ? '1' : '2');
        $this->element($document, $node, 'P_18A', $annotations['split_payment'] ? '1' : '2');
        $exemption = $this->element($document, $node, 'Zwolnienie');
        $this->element($document, $exemption, 'P_19N', '1');
        $transport = $this->element($document, $node, 'NoweSrodkiTransportu');
        $this->element($document, $transport, 'P_22N', '1');
        $this->element($document, $node, 'P_23', $annotations['triangular_transaction'] ? '1' : '2');
        $margin = $this->element($document, $node, 'PMarzy');
        $this->element($document, $margin, 'P_PMarzyN', '1');
    }

    /** @param array<string, string> $registrations */
    private function footer(DOMDocument $document, DOMElement $root, array $registrations): void
    {
        if ($registrations === []) {
            return;
        }

        $footer = $this->element($document, $root, 'Stopka');
        $registers = $this->element($document, $footer, 'Rejestry');
        if (isset($registrations['regon'])) {
            $this->element($document, $registers, 'REGON', $registrations['regon']);
        }
        if (isset($registrations['bdo'])) {
            $this->element($document, $registers, 'BDO', $registrations['bdo']);
        }
    }

    /** @param array<string, string> $address */
    private function address(DOMDocument $document, DOMElement $subject, array $address): void
    {
        $node = $this->element($document, $subject, 'Adres');
        $this->element($document, $node, 'KodKraju', $address['country_code']);
        $this->element($document, $node, 'AdresL1', $address['line_1']);
        if (isset($address['line_2'])) {
            $this->element($document, $node, 'AdresL2', $address['line_2']);
        }
    }

    private function element(
        DOMDocument $document,
        DOMElement $parent,
        string $name,
        ?string $value = null,
    ): DOMElement {
        $element = $document->createElementNS(self::NAMESPACE, $name);
        if ($value !== null) {
            $element->appendChild($document->createTextNode($value));
        }
        $parent->appendChild($element);

        return $element;
    }
}
