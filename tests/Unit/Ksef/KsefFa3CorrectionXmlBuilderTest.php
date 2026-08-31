<?php

namespace Tests\Unit\Ksef;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionXmlBuilder;
use Modules\Ksef\Services\Fa3\KsefFa3SchemaValidator;
use Modules\Ksef\Services\Fa3\KsefFa3XmlBuilder;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionDocumentData;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionSourceReference;
use Tests\TestCase;

class KsefFa3CorrectionXmlBuilderTest extends TestCase
{
    public function test_it_builds_schema_valid_before_after_kor_in_exact_xsd_order(): void
    {
        $data = $this->data(
            source: KsefFa3CorrectionSourceReference::ksef(
                environment: KsefEnvironment::Production,
                rootInvoiceId: 10,
                rootInvoiceNumber: 'FV/10/2026',
                correctedInvoiceIssueDate: '2026-08-20',
                rootSubmissionId: 20,
                rootKsefNumber: '9876543210-20260819-000000000001-4A',
                precedingCorrections: [],
            ),
            buyerBefore: $this->buyer('Stary Nabywca', 'Stara 1'),
            buyerLinkId: 'NB/01',
            lines: [[
                'position' => 1,
                'before' => $this->line('Towar przed', '1', '100.00', '100.00', '23'),
                'after' => $this->line('Towar po ąęł', '2', '100.00', '200.00', '23'),
            ]],
        );

        $xml = app(KsefFa3CorrectionXmlBuilder::class)->build($data);
        app(KsefFa3SchemaValidator::class)->validate($xml);
        $xpath = $this->xpath($xml);

        $this->assertSame('KOR', $this->value($xpath, '//fa:Fa/fa:RodzajFaktury'));
        $this->assertSame('Pomyłka: ą ć ę ł ń ó ś ź ż', $this->value($xpath, '//fa:Fa/fa:PrzyczynaKorekty'));
        $this->assertSame(0, $xpath->query('//fa:Fa/fa:TypKorekty')->length);
        $this->assertSame(1, $xpath->query('//fa:DaneFaKorygowanej/fa:NrKSeF')->length);
        $this->assertSame(1, $xpath->query('//fa:DaneFaKorygowanej/fa:NrKSeFFaKorygowanej')->length);
        $this->assertSame(0, $xpath->query('//fa:DaneFaKorygowanej/fa:NrKSeFN')->length);
        $this->assertSame('NB/01', $this->value($xpath, '/fa:Faktura/fa:Podmiot2/fa:IDNabywcy'));
        $this->assertSame('NB/01', $this->value($xpath, '//fa:Fa/fa:Podmiot2K/fa:IDNabywcy'));
        $this->assertSame('Stary Nabywca', $this->value($xpath, '//fa:Podmiot2K/fa:DaneIdentyfikacyjne/fa:Nazwa'));

        $rows = $xpath->query('//fa:Fa/fa:FaWiersz');
        $this->assertNotFalse($rows);
        $this->assertSame(2, $rows->length);
        $this->assertSame('1', $this->value($xpath, '(//fa:FaWiersz)[1]/fa:NrWierszaFa'));
        $this->assertSame('1', $this->value($xpath, '(//fa:FaWiersz)[2]/fa:NrWierszaFa'));
        $this->assertSame('1', $this->value($xpath, '(//fa:FaWiersz)[1]/fa:StanPrzed'));
        $this->assertSame(0, $xpath->query('(//fa:FaWiersz)[2]/fa:StanPrzed')->length);
        $this->assertSame(
            ['NrWierszaFa', 'P_7', 'P_8A', 'P_8B', 'P_9A', 'P_11', 'P_12', 'StanPrzed'],
            $this->childNames($rows->item(0)),
        );
        $this->assertSame(
            ['RodzajFaktury', 'PrzyczynaKorekty', 'DaneFaKorygowanej', 'Podmiot2K', 'FaWiersz', 'FaWiersz'],
            array_values(array_filter(
                $this->childNames($xpath->query('//fa:Fa')->item(0)),
                static fn (string $name): bool => in_array($name, [
                    'RodzajFaktury', 'PrzyczynaKorekty', 'DaneFaKorygowanej', 'Podmiot2K', 'FaWiersz',
                ], true),
            )),
        );
        $this->assertStringContainsString('Towar po ąęł', $xml);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringNotContainsString('<?xml-stylesheet', $xml);
    }

    public function test_outside_root_uses_the_exclusive_nr_ksef_n_choice_and_omits_buyer_linkage(): void
    {
        $data = $this->data(
            source: KsefFa3CorrectionSourceReference::outsideKsef(
                environment: KsefEnvironment::Production,
                rootInvoiceId: 10,
                rootInvoiceNumber: 'FV/10/2026',
                correctedInvoiceIssueDate: '2026-08-20',
                rootProvenanceId: 30,
                precedingCorrections: [],
            ),
            buyerBefore: null,
            buyerLinkId: null,
            lines: [],
            gross: '0.00',
            buckets: array_fill_keys($this->bucketKeys(), null),
        );

        $xml = app(KsefFa3CorrectionXmlBuilder::class)->build($data);
        app(KsefFa3SchemaValidator::class)->validate($xml);
        $xpath = $this->xpath($xml);

        $this->assertSame(0, $xpath->query('//fa:DaneFaKorygowanej/fa:NrKSeF')->length);
        $this->assertSame(0, $xpath->query('//fa:DaneFaKorygowanej/fa:NrKSeFFaKorygowanej')->length);
        $this->assertSame(1, $xpath->query('//fa:DaneFaKorygowanej/fa:NrKSeFN')->length);
        $this->assertSame(0, $xpath->query('//fa:Podmiot2K|//fa:IDNabywcy|//fa:FaWiersz')->length);
        $this->assertSame('0.00', $this->value($xpath, '//fa:Fa/fa:P_15'));
    }

    /**
     * @param  array<string, mixed>|null  $buyerBefore
     * @param  list<array{position: int, before: array<string, string|int>, after: array<string, string|int>}>  $lines
     * @param  array<string, array<string, string>|null>|null  $buckets
     */
    private function data(
        KsefFa3CorrectionSourceReference $source,
        ?array $buyerBefore,
        ?string $buyerLinkId,
        array $lines,
        string $gross = '123.00',
        ?array $buckets = null,
    ): KsefFa3CorrectionDocumentData {
        return new KsefFa3CorrectionDocumentData(
            generatedAt: '2026-08-30T10:34:56Z',
            seller: [
                'taxpayer_prefix' => null,
                'nip' => '9876543210',
                'name' => 'NEX Sprzedawca',
                'address' => ['country_code' => 'PL', 'line_1' => 'Sprzedawcy 1', 'line_2' => '40-001 Katowice'],
            ],
            buyerAfter: $this->buyer('Nowy Nabywca', 'Nowa 2'),
            buyerBefore: $buyerBefore,
            buyerLinkId: $buyerLinkId,
            invoice: [
                'currency' => 'PLN',
                'issue_date' => '2026-08-21',
                'place_of_issue' => 'Katowice',
                'number' => 'KOR/1/2026',
                'sale_date' => '2026-08-20',
                'total_gross' => $gross,
            ],
            taxBuckets: $buckets ?? [
                'standard_1' => ['net' => '100.00', 'vat' => '23.00'],
                'standard_2' => null,
                'standard_3' => null,
                'domestic_zero' => null,
                'wdt' => null,
                'export' => null,
            ],
            annotations: [
                'cash_accounting' => false,
                'self_billing' => false,
                'reverse_charge' => false,
                'split_payment' => false,
                'new_transport_mean' => false,
                'triangular_transaction' => false,
                'margin_scheme' => false,
            ],
            lines: $lines,
            sourceReference: $source,
            reason: 'Pomyłka: ą ć ę ł ń ó ś ź ż',
        );
    }

    /** @return array<string, mixed> */
    private function buyer(string $name, string $line): array
    {
        return [
            'identity_type' => 'pl_nip',
            'identity_country_code' => 'PL',
            'identity_identifier' => '5260250995',
            'name' => $name,
            'address' => ['country_code' => 'PL', 'line_1' => $line, 'line_2' => '00-001 Warszawa'],
            'jst' => false,
            'vat_group' => false,
        ];
    }

    /** @return array<string, string|int> */
    private function line(string $name, string $quantity, string $unitNet, string $totalNet, string $rate): array
    {
        return [
            'name' => $name,
            'unit_name' => 'szt.',
            'quantity' => $quantity,
            'unit_price_net' => $unitNet,
            'total_net' => $totalNet,
            'total_vat' => $quantity === '1' ? '23.00' : '46.00',
            'total_gross' => $quantity === '1' ? '123.00' : '246.00',
            'fa3_rate' => $rate,
        ];
    }

    /** @return list<string> */
    private function bucketKeys(): array
    {
        return ['standard_1', 'standard_2', 'standard_3', 'domestic_zero', 'wdt', 'export'];
    }

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fa', KsefFa3XmlBuilder::NAMESPACE);

        return $xpath;
    }

    private function value(DOMXPath $xpath, string $expression): string
    {
        $nodes = $xpath->query($expression);
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, $expression);

        return trim((string) $nodes->item(0)?->nodeValue);
    }

    /** @return list<string> */
    private function childNames(?\DOMNode $node): array
    {
        $this->assertInstanceOf(DOMElement::class, $node);
        $names = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $names[] = $child->localName;
            }
        }

        return $names;
    }
}
