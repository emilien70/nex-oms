<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionDocumentMapper;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionSourceReferenceResolver;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\Ksef\CreatesKsefFa3CorrectionScenarios;
use Tests\TestCase;

class KsefFa3CorrectionDocumentMapperTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use CreatesKsefFa3CorrectionScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_it_maps_frozen_before_after_lines_and_difference_summary(): void
    {
        $settings = $this->ksefSettings();
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root);
        $correction = $this->issueKsefFinancialCorrection($root, 2);
        $reference = app(KsefFa3CorrectionSourceReferenceResolver::class)->resolve(
            $correction,
            $settings->environment,
        );

        $data = app(KsefFa3CorrectionDocumentMapper::class)->map(
            $correction,
            $reference,
            CarbonImmutable::parse('2026-08-30 12:34:56', 'Europe/Warsaw'),
        );

        $this->assertSame('2026-08-30T10:34:56Z', $data->generatedAt);
        $this->assertSame($root->number, $data->sourceReference->rootInvoiceNumber);
        $this->assertSame('123.00', $data->invoice['total_gross']);
        $this->assertSame('100.00', $data->taxBuckets['standard_1']['net']);
        $this->assertSame('23.00', $data->taxBuckets['standard_1']['vat']);
        $this->assertCount(1, $data->lines);
        $this->assertSame('1', $data->lines[0]['before']['quantity']);
        $this->assertSame('2', $data->lines[0]['after']['quantity']);
        $this->assertSame('23', $data->lines[0]['before']['fa3_rate']);
        $this->assertSame('23', $data->lines[0]['after']['fa3_rate']);
        $this->assertNull($data->buyerBefore);
        $this->assertNull($data->buyerLinkId);
        $this->assertFalse($data->annotations['split_payment']);
        Http::assertNothingSent();
    }

    public function test_missing_root_annotations_fail_closed_instead_of_falling_back_to_false(): void
    {
        $settings = $this->ksefSettings();
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root);
        $correction = $this->issueKsefFinancialCorrection($root);
        $metadata = $root->tax_metadata_snapshot;
        unset($metadata['ksef_tax']['annotations']);
        $root->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $correction->unsetRelation('correctedInvoice');
        $reference = app(KsefFa3CorrectionSourceReferenceResolver::class)->resolve(
            $correction,
            $settings->environment,
        );

        try {
            app(KsefFa3CorrectionDocumentMapper::class)->map(
                $correction->refresh(),
                $reference,
                CarbonImmutable::parse('2026-08-30 12:34:56', 'Europe/Warsaw'),
            );
            $this->fail('Expected missing annotations error.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('ksef_fa3_correction_annotations_unresolved', $exception->errorCode());
        }
    }
}
