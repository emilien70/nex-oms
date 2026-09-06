<?php

namespace Tests\Unit\Ksef;

use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\Fa3\KsefFa3XmlBuilder;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionBusinessFingerprintService;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3DocumentData;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class KsefOfflineTechnicalCorrectionBusinessFingerprintServiceTest extends TestCase
{
    public function test_canonical_projection_is_deterministic_and_ignores_only_generated_at_and_decimal_formatting(): void
    {
        $original = $this->xml('2026-09-06T10:00:00Z');
        $equivalent = $this->xml('2026-09-06T11:30:00Z');
        $equivalent = str_replace(
            [
                'kodSystemowy="FA (3)" wersjaSchemy="1-0E"',
                '<P_8B>1</P_8B>',
                '<P_9A>100.00</P_9A>',
                '<P_11>100.00</P_11>',
                '<P_15>123.00</P_15>',
            ],
            [
                'wersjaSchemy="1-0E" kodSystemowy="FA (3)"',
                '<P_8B>1.0000</P_8B>',
                '<P_9A>100.0</P_9A>',
                '<P_11>100.0</P_11>',
                '<P_15>123.0</P_15>',
            ],
            $equivalent,
        );

        $service = app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class);
        $first = $service->fromPayload($original, 1);

        $this->assertSame(44, strlen($first));
        $this->assertSame($first, $service->fromPayload($original, 1));
        $this->assertSame($first, $service->fromPayload($equivalent, 1));
    }

    #[DataProvider('businessChanges')]
    public function test_each_material_business_change_changes_the_fingerprint(
        string $needle,
        string $replacement,
    ): void {
        $xml = $this->xml('2026-09-06T10:00:00Z');
        $this->assertStringContainsString($needle, $xml);
        $changed = str_replace($needle, $replacement, $xml);
        $service = app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class);

        $this->assertNotSame(
            $service->fromPayload($xml, 1),
            $service->fromPayload($changed, 1),
        );
    }

    public static function businessChanges(): array
    {
        return [
            'buyer' => ['<Nazwa>Buyer SA</Nazwa>', '<Nazwa>Changed Buyer SA</Nazwa>'],
            'currency' => ['<KodWaluty>PLN</KodWaluty>', '<KodWaluty>EUR</KodWaluty>'],
            'quantity' => ['<P_8B>1</P_8B>', '<P_8B>2</P_8B>'],
            'unit price' => ['<P_9A>100.00</P_9A>', '<P_9A>101.00</P_9A>'],
            'VAT treatment' => ['<P_12>23</P_12>', '<P_12>8</P_12>'],
            'VAT summary' => ['<P_14_1>23.00</P_14_1>', '<P_14_1>24.00</P_14_1>'],
            'gross total' => ['<P_15>123.00</P_15>', '<P_15>124.00</P_15>'],
        ];
    }

    public function test_doctype_and_unknown_projection_version_fail_closed(): void
    {
        $service = app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class);
        $xml = $this->xml('2026-09-06T10:00:00Z');

        foreach ([
            '<!DOCTYPE Faktura [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'.$xml,
            $xml,
        ] as $index => $payload) {
            try {
                $service->fromPayload($payload, $index === 0 ? 1 : 999);
                $this->fail('Unsafe XML or an unknown fingerprint version should fail closed.');
            } catch (KsefApiException $exception) {
                $this->assertSame(
                    $index === 0
                        ? 'ksef_technical_correction_business_projection_invalid'
                        : 'ksef_technical_correction_business_fingerprint_version_unsupported',
                    $exception->safeCode,
                );
            }
        }
    }

    private function xml(string $generatedAt): string
    {
        return app(KsefFa3XmlBuilder::class)->build(new KsefFa3DocumentData(
            generatedAt: $generatedAt,
            seller: [
                'taxpayer_prefix' => 'PL',
                'nip' => '9876543210',
                'name' => 'Seller SA',
                'address' => [
                    'country_code' => 'PL',
                    'line_1' => 'Seller Street 1',
                    'line_2' => '00-001 Warsaw',
                ],
            ],
            buyer: [
                'identity_type' => 'pl_nip',
                'identity_country_code' => 'PL',
                'identity_identifier' => '5260250995',
                'name' => 'Buyer SA',
                'address' => [
                    'country_code' => 'PL',
                    'line_1' => 'Buyer Street 2',
                    'line_2' => '00-002 Warsaw',
                ],
                'contacts' => ['email' => 'buyer@example.test', 'phone' => '123456789'],
                'jst' => false,
                'vat_group' => false,
            ],
            invoice: [
                'currency' => 'PLN',
                'issue_date' => '2026-09-06',
                'place_of_issue' => 'Warsaw',
                'number' => 'FV 1/2026',
                'sale_date' => '2026-09-05',
                'total_gross' => '123.00',
            ],
            taxBuckets: [
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
                'split_payment' => true,
                'new_transport_mean' => false,
                'triangular_transaction' => false,
                'margin_scheme' => false,
            ],
            lines: [[
                'position' => 1,
                'name' => 'Service',
                'unit_name' => 'szt.',
                'quantity' => '1',
                'unit_price_net' => '100.00',
                'total_net' => '100.00',
                'fa3_rate' => '23',
                'gtu' => 'GTU_12',
            ]],
            registrations: ['regon' => '123456789', 'bdo' => '000000001'],
            recipient: [
                'name' => 'Recipient SA',
                'address' => [
                    'country_code' => 'PL',
                    'line_1' => 'Recipient Street 3',
                    'line_2' => '00-003 Warsaw',
                ],
                'role_description' => 'Odbiorca dostawy',
            ],
            additionalDescriptions: [
                ['key' => 'Informacja dodatkowa 1', 'value' => 'First value'],
                ['key' => 'Informacja dodatkowa 2', 'value' => 'Second value'],
            ],
            payment: [
                'due_date' => '2026-09-20',
                'method_code' => '6',
                'bank_account' => [
                    'number' => 'PL61109010140000071219812874',
                    'swift' => 'WBKPPLPP',
                    'name' => 'Test Bank',
                ],
            ],
            transactionTerms: [
                'date' => '2026-09-01',
                'number' => 'ORDER-1',
            ],
        ));
    }
}
