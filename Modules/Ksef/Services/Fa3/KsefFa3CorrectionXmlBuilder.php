<?php

namespace Modules\Ksef\Services\Fa3;

use DOMDocument;
use DOMElement;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Ksef\Enums\KsefFa3CorrectionRootReferenceType;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionDocumentData;

final class KsefFa3CorrectionXmlBuilder
{
    public function build(KsefFa3CorrectionDocumentData $data): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $document->preserveWhiteSpace = false;

        $root = $document->createElementNS(KsefFa3XmlBuilder::NAMESPACE, 'Faktura');
        $document->appendChild($root);
        $this->header($document, $root, $data->generatedAt);
        $this->seller($document, $root, $data->seller);
        $this->buyer($document, $root, $data->buyerAfter, $data->buyerLinkId);
        $this->invoice($document, $root, $data);

        $xml = $document->saveXML();
        if (! is_string($xml)) {
            throw new InvoiceDomainException(
                'ksef_fa3_correction_xml_build_failed',
                'Nie można utworzyć dokumentu XML Korekty FA(3).',
            );
        }

        return $xml;
    }

    private function header(DOMDocument $document, DOMElement $root, string $generatedAt): void
    {
        $header = $this->element($document, $root, 'Naglowek');
        $formCode = $this->element($document, $header, 'KodFormularza', 'FA');
        $formCode->setAttribute('kodSystemowy', 'FA (3)');
        $formCode->setAttribute('wersjaSchemy', '1-0E');
        $this->element($document, $header, 'WariantFormularza', '3');
        $this->element($document, $header, 'DataWytworzeniaFa', $generatedAt);
        $this->element($document, $header, 'SystemInfo', 'NEX-OMS');
    }

    /** @param array<string, mixed> $seller */
    private function seller(DOMDocument $document, DOMElement $root, array $seller): void
    {
        $subject = $this->element($document, $root, 'Podmiot1');
        if (is_string($seller['taxpayer_prefix'] ?? null)) {
            $this->element($document, $subject, 'PrefiksPodatnika', $seller['taxpayer_prefix']);
        }
        $identity = $this->element($document, $subject, 'DaneIdentyfikacyjne');
        $this->element($document, $identity, 'NIP', (string) $seller['nip']);
        $this->element($document, $identity, 'Nazwa', (string) $seller['name']);
        $this->address($document, $subject, $seller['address']);
    }

    /** @param array<string, mixed> $buyer */
    private function buyer(
        DOMDocument $document,
        DOMElement $root,
        array $buyer,
        ?string $buyerLinkId,
    ): void {
        $subject = $this->element($document, $root, 'Podmiot2');
        $this->buyerIdentity($document, $subject, $buyer);
        $this->address($document, $subject, $buyer['address']);
        if ($buyerLinkId !== null) {
            $this->element($document, $subject, 'IDNabywcy', $buyerLinkId);
        }
        $this->element($document, $subject, 'JST', ($buyer['jst'] ?? false) ? '1' : '2');
        $this->element($document, $subject, 'GV', ($buyer['vat_group'] ?? false) ? '1' : '2');
    }

    private function invoice(
        DOMDocument $document,
        DOMElement $root,
        KsefFa3CorrectionDocumentData $data,
    ): void {
        $invoice = $this->element($document, $root, 'Fa');
        $this->element($document, $invoice, 'KodWaluty', (string) $data->invoice['currency']);
        $this->element($document, $invoice, 'P_1', (string) $data->invoice['issue_date']);
        if (is_string($data->invoice['place_of_issue'])) {
            $this->element($document, $invoice, 'P_1M', $data->invoice['place_of_issue']);
        }
        $this->element($document, $invoice, 'P_2', (string) $data->invoice['number']);
        if (is_string($data->invoice['sale_date']) && $data->invoice['sale_date'] !== '') {
            $this->element($document, $invoice, 'P_6', $data->invoice['sale_date']);
        }
        $this->taxSummary($document, $invoice, $data->taxBuckets);
        $this->element($document, $invoice, 'P_15', (string) $data->invoice['total_gross']);
        $this->annotations($document, $invoice, $data->annotations);
        $this->element($document, $invoice, 'RodzajFaktury', 'KOR');
        $this->element($document, $invoice, 'PrzyczynaKorekty', $data->reason);
        $this->correctedInvoiceReference($document, $invoice, $data);
        if ($data->buyerBefore !== null && $data->buyerLinkId !== null) {
            $this->buyerBefore($document, $invoice, $data->buyerBefore, $data->buyerLinkId);
        }

        foreach ($data->lines as $line) {
            $this->line($document, $invoice, $line['position'], $line['before'], true);
            $this->line($document, $invoice, $line['position'], $line['after'], false);
        }
    }

    private function correctedInvoiceReference(
        DOMDocument $document,
        DOMElement $invoice,
        KsefFa3CorrectionDocumentData $data,
    ): void {
        $reference = $data->sourceReference;
        $node = $this->element($document, $invoice, 'DaneFaKorygowanej');
        $this->element($document, $node, 'DataWystFaKorygowanej', $reference->correctedInvoiceIssueDate);
        $this->element($document, $node, 'NrFaKorygowanej', $reference->rootInvoiceNumber);

        if ($reference->rootReferenceType === KsefFa3CorrectionRootReferenceType::Ksef) {
            if ($reference->rootKsefNumber === null) {
                throw $this->buildError();
            }
            $this->element($document, $node, 'NrKSeF', '1');
            $this->element($document, $node, 'NrKSeFFaKorygowanej', $reference->rootKsefNumber);

            return;
        }

        $this->element($document, $node, 'NrKSeFN', '1');
    }

    /** @param array<string, mixed> $buyer */
    private function buyerBefore(
        DOMDocument $document,
        DOMElement $invoice,
        array $buyer,
        string $buyerLinkId,
    ): void {
        $subject = $this->element($document, $invoice, 'Podmiot2K');
        $this->buyerIdentity($document, $subject, $buyer);
        $this->address($document, $subject, $buyer['address']);
        $this->element($document, $subject, 'IDNabywcy', $buyerLinkId);
    }

    /** @param array<string, mixed> $buyer */
    private function buyerIdentity(DOMDocument $document, DOMElement $subject, array $buyer): void
    {
        $identity = $this->element($document, $subject, 'DaneIdentyfikacyjne');
        match ($buyer['identity_type']) {
            'pl_nip' => $this->element($document, $identity, 'NIP', (string) $buyer['identity_identifier']),
            'eu_vat' => $this->euVatIdentity($document, $identity, $buyer),
            'none' => $this->element($document, $identity, 'BrakID', '1'),
            default => throw $this->buildError(),
        };
        if (is_string($buyer['name'] ?? null)) {
            $this->element($document, $identity, 'Nazwa', $buyer['name']);
        }
    }

    /** @param array<string, mixed> $buyer */
    private function euVatIdentity(DOMDocument $document, DOMElement $identity, array $buyer): DOMElement
    {
        $this->element($document, $identity, 'KodUE', (string) $buyer['identity_country_code']);

        return $this->element($document, $identity, 'NrVatUE', (string) $buyer['identity_identifier']);
    }

    /**
     * @param  array<string, string|int>  $line
     */
    private function line(
        DOMDocument $document,
        DOMElement $invoice,
        int $position,
        array $line,
        bool $before,
    ): void {
        $row = $this->element($document, $invoice, 'FaWiersz');
        $this->element($document, $row, 'NrWierszaFa', (string) $position);
        $this->element($document, $row, 'P_7', (string) $line['name']);
        $this->element($document, $row, 'P_8A', (string) $line['unit_name']);
        $this->element($document, $row, 'P_8B', (string) $line['quantity']);
        $this->element($document, $row, 'P_9A', (string) $line['unit_price_net']);
        $this->element($document, $row, 'P_11', (string) $line['total_net']);
        $this->element($document, $row, 'P_12', (string) $line['fa3_rate']);
        if ($before) {
            $this->element($document, $row, 'StanPrzed', '1');
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
        $element = $document->createElementNS(KsefFa3XmlBuilder::NAMESPACE, $name);
        if ($value !== null) {
            $element->appendChild($document->createTextNode($value));
        }
        $parent->appendChild($element);

        return $element;
    }

    private function buildError(): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'ksef_fa3_correction_xml_build_failed',
            'Nie można utworzyć dokumentu XML Korekty FA(3).',
        );
    }
}
