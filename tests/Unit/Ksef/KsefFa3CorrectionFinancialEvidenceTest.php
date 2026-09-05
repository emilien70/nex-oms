<?php

namespace Tests\Unit\Ksef;

use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionFinancialEvidenceBuilder;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionFinancialEvidenceValidator;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionTaxBuckets;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionDocumentData;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionSourceReference;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3GeneratedDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KsefFa3CorrectionFinancialEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_builder_copies_mapped_amounts_and_converted_vat_without_reconstruction(): void
    {
        $data = $this->data();
        $evidence = app(KsefFa3CorrectionFinancialEvidenceBuilder::class)->build($data);
        $this->assertSame(1, $evidence['version']);
        $this->assertSame('correction_financial', $evidence['profile']);
        $this->assertSame($data->lines, $evidence['lines']);
        $this->assertSame($data->taxBuckets, $evidence['tax_buckets']);
        $this->assertSame(['net' => '0.00', 'vat' => '0.01', 'gross' => '0.01'], $evidence['totals']);
        $this->assertSame('0.04', $evidence['tax_buckets']['standard_1']['pln_vat']);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidMappedData')]
    public function test_builder_rejects_inconsistent_mapped_data_before_returning_evidence(string $case): void
    {
        $data = $this->data($case);
        try {
            app(KsefFa3CorrectionFinancialEvidenceBuilder::class)->build($data);
            $this->fail('Expected inconsistent financial evidence to be rejected');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_offline_presentation_integrity_invalid', $exception->safeCode);
        }
        Http::assertNothingSent();
    }

    public static function invalidMappedData(): array
    {
        return [['line_sum'], ['bucket'], ['gross'], ['unused'], ['noncanonical_zero'], ['pln_currency'], ['zero_rate_vat']];
    }

    public function test_missing_evidence_fails_closed_but_ordinary_generated_result_defaults_to_null(): void
    {
        $ordinary = new KsefFa3GeneratedDocument('<Faktura/>', '2026-09-05T10:00:00Z', 'FA (3) 1-0E');
        $this->assertNull($ordinary->integrityEvidence);
        $this->expectException(KsefApiException::class);
        app(KsefFa3CorrectionFinancialEvidenceValidator::class)->validate(null);
    }

    private function data(?string $case = null): KsefFa3CorrectionDocumentData
    {
        $lines = [[
            'position' => 1,
            'before' => ['fa3_rate' => '23', 'total_net' => '0.02', 'total_vat' => '0.00', 'total_gross' => '0.02'],
            'after' => ['fa3_rate' => '23', 'total_net' => '0.02', 'total_vat' => '0.01', 'total_gross' => '0.03'],
        ]];
        $buckets = array_fill_keys(array_keys(KsefFa3CorrectionTaxBuckets::FIELDS), null);
        $buckets['standard_1'] = ['net' => '0.00', 'vat' => '0.01', 'pln_vat' => '0.04'];
        $gross = '0.01';
        match ($case) {
            'line_sum' => $lines[0]['after']['total_gross'] = '0.04',
            'bucket' => $buckets['standard_1']['vat'] = '0.00',
            'gross' => $gross = '0.00',
            'unused' => $buckets['standard_2'] = ['net' => '0.00', 'vat' => '0.00', 'pln_vat' => '0.01'],
            'noncanonical_zero' => $buckets['standard_2'] = ['net' => '0.00', 'vat' => '0.00'],
            'zero_rate_vat' => $lines[0]['after']['fa3_rate'] = '0 KR',
            default => null,
        };

        return new KsefFa3CorrectionDocumentData(
            generatedAt: '2026-09-05T10:00:00Z',
            seller: [], buyerAfter: [], buyerBefore: null, buyerLinkId: null,
            invoice: ['currency' => $case === 'pln_currency' ? 'PLN' : 'EUR', 'total_gross' => $gross],
            taxBuckets: $buckets, annotations: [], lines: $lines,
            sourceReference: KsefFa3CorrectionSourceReference::outsideKsef(KsefEnvironment::Test, 1, 'FAKE/1', '2026-09-04', 1, []),
            reason: 'Synthetic financial evidence test',
        );
    }
}
