<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefFa3CorrectionRootReferenceType;
use Modules\Ksef\Enums\KsefInvoiceProvenanceType;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefInvoiceProvenance;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionSourceReferenceResolver;
use Modules\Ksef\Services\KsefInvoiceProvenanceService;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionSourceReference;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefFa3CorrectionSourceReferenceResolverTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_resolves_an_accepted_production_root_without_requiring_target_submission(): void
    {
        $root = $this->rootInvoice();
        $rootSubmission = $this->submission($root, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::Accepted);
        [$target] = $this->correctionChain($root, 1);

        $reference = $this->resolve($target, KsefEnvironment::Production);

        $this->assertSame(KsefEnvironment::Production, $reference->environment);
        $this->assertSame($root->getKey(), $reference->rootInvoiceId);
        $this->assertSame('FV/100/2026', $reference->rootInvoiceNumber);
        $this->assertSame('2026-08-20', $reference->correctedInvoiceIssueDate);
        $this->assertSame(KsefFa3CorrectionRootReferenceType::Ksef, $reference->rootReferenceType);
        $this->assertSame($rootSubmission->getKey(), $reference->rootSubmissionId);
        $this->assertSame($rootSubmission->ksef_number, $reference->rootKsefNumber);
        $this->assertNull($reference->rootProvenanceId);
        $this->assertSame([], $reference->precedingCorrections);
        $this->assertDatabaseMissing('ksef_invoice_submissions', ['invoice_id' => $target->getKey()]);
    }

    #[DataProvider('nonProductionEnvironmentProvider')]
    public function test_production_never_uses_acceptance_from_a_test_environment(
        KsefEnvironment $otherEnvironment,
    ): void {
        $root = $this->rootInvoice();
        $this->submission($root, $otherEnvironment, KsefInvoiceSubmissionStatus::Accepted);
        [$target] = $this->correctionChain($root, 1);

        $exception = $this->expectDomainError(
            'ksef_fa3_correction_source_ksef_environment_mismatch',
            fn () => $this->resolve($target, KsefEnvironment::Production),
        );

        $this->assertSame('production', $exception->metadata()['environment']);
        $this->assertSame([$otherEnvironment->value], $exception->metadata()['accepted_environments']);
    }

    public function test_production_selects_only_production_when_demo_is_also_accepted(): void
    {
        $root = $this->rootInvoice();
        $production = $this->submission(
            $root,
            KsefEnvironment::Production,
            KsefInvoiceSubmissionStatus::Accepted,
            ['ksef_number' => $this->validKsefNumber(self::SELLER_NIP, '0100001AF629')],
        );
        $this->submission(
            $root,
            KsefEnvironment::Demo,
            KsefInvoiceSubmissionStatus::Accepted,
            ['ksef_number' => $this->validKsefNumber(self::SELLER_NIP, '0100001AF630')],
        );
        [$target] = $this->correctionChain($root, 1);

        $reference = $this->resolve($target, KsefEnvironment::Production);

        $this->assertSame($production->getKey(), $reference->rootSubmissionId);
        $this->assertSame($production->ksef_number, $reference->rootKsefNumber);
    }

    public function test_demo_can_be_resolved_explicitly_for_developer_testing(): void
    {
        $root = $this->rootInvoice();
        $demo = $this->submission($root, KsefEnvironment::Demo, KsefInvoiceSubmissionStatus::Accepted);
        [$target] = $this->correctionChain($root, 1);

        $reference = $this->resolve($target, KsefEnvironment::Demo);

        $this->assertSame(KsefEnvironment::Demo, $reference->environment);
        $this->assertSame($demo->getKey(), $reference->rootSubmissionId);
    }

    public function test_root_without_any_submission_is_unresolved_without_outside_ksef_guess(): void
    {
        $root = $this->rootInvoice();
        [$target] = $this->correctionChain($root, 1);

        $exception = $this->expectDomainError(
            'ksef_fa3_correction_source_ksef_unresolved',
            fn () => $this->resolve($target, KsefEnvironment::Production),
        );

        $this->assertSame([], $exception->metadata()['submission_statuses']);
        $this->assertArrayNotHasKey('NrKSeFN', $exception->metadata());
    }

    #[DataProvider('nonAcceptedStatusProvider')]
    public function test_non_accepted_production_history_fails_closed(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $root = $this->rootInvoice();
        $this->submission($root, KsefEnvironment::Production, $status);
        [$target] = $this->correctionChain($root, 1);

        $exception = $this->expectDomainError(
            'ksef_fa3_correction_source_ksef_not_accepted',
            fn () => $this->resolve($target, KsefEnvironment::Production),
        );

        $this->assertSame([$status->value], $exception->metadata()['submission_statuses']);
    }

    public function test_historical_technical_failure_before_one_accepted_submission_is_allowed(): void
    {
        $root = $this->rootInvoice();
        $this->submission($root, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::TechnicalFailed);
        $accepted = $this->submission($root, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::Accepted);
        [$target] = $this->correctionChain($root, 1);

        $reference = $this->resolve($target, KsefEnvironment::Production);

        $this->assertSame($accepted->getKey(), $reference->rootSubmissionId);
    }

    public function test_rejected_offline_source_before_one_accepted_technical_result_uses_the_accepted_number(): void
    {
        $root = $this->rootInvoice();
        $this->submission($root, KsefEnvironment::Demo, KsefInvoiceSubmissionStatus::Rejected);
        $acceptedTechnical = $this->submission(
            $root,
            KsefEnvironment::Demo,
            KsefInvoiceSubmissionStatus::Accepted,
            ['ksef_number' => $this->validKsefNumber(self::SELLER_NIP, '0600001AF629')],
        );
        [$target] = $this->correctionChain($root, 1);

        $reference = $this->resolve($target, KsefEnvironment::Demo);

        $this->assertSame($acceptedTechnical->getKey(), $reference->rootSubmissionId);
        $this->assertSame($acceptedTechnical->ksef_number, $reference->rootKsefNumber);
        $this->assertSame(KsefFa3CorrectionRootReferenceType::Ksef, $reference->rootReferenceType);
    }

    public function test_multiple_accepted_root_submissions_in_one_environment_are_ambiguous(): void
    {
        $root = $this->rootInvoice();
        $this->submission($root, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::Accepted);
        $this->submission(
            $root,
            KsefEnvironment::Production,
            KsefInvoiceSubmissionStatus::Accepted,
            ['ksef_number' => $this->validKsefNumber(self::SELLER_NIP, '0100001AF630')],
        );
        [$target] = $this->correctionChain($root, 1);

        $this->expectDomainError(
            'ksef_fa3_correction_source_ksef_ambiguous',
            fn () => $this->resolve($target, KsefEnvironment::Production),
        );
    }

    #[DataProvider('invalidRootReferenceProvider')]
    public function test_invalid_accepted_root_reference_fails_closed(array $attributes): void
    {
        $root = $this->rootInvoice();
        $this->submission(
            $root,
            KsefEnvironment::Production,
            KsefInvoiceSubmissionStatus::Accepted,
            $attributes,
        );
        [$target] = $this->correctionChain($root, 1);

        $this->expectDomainError(
            'ksef_fa3_correction_source_ksef_reference_invalid',
            fn () => $this->resolve($target, KsefEnvironment::Production),
        );
    }

    public function test_context_change_does_not_invalidate_historical_reference_or_replace_root_issue_date(): void
    {
        $root = $this->rootInvoice();
        $this->submission(
            $root,
            KsefEnvironment::Production,
            KsefInvoiceSubmissionStatus::Accepted,
            [
                'context_nip' => '5265877635',
                'invoicing_date' => CarbonImmutable::parse('2026-08-29T14:00:00Z'),
            ],
        );
        [$target] = $this->correctionChain($root, 1);

        $reference = $this->resolve($target, KsefEnvironment::Production);

        $this->assertSame('2026-08-20', $reference->correctedInvoiceIssueDate);
    }

    public function test_second_correction_requires_and_returns_the_first_production_reference(): void
    {
        $root = $this->rootInvoice();
        $this->submission($root, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::Accepted);
        [$first, $target] = $this->correctionChain($root, 2);
        $firstSubmission = $this->submission(
            $first,
            KsefEnvironment::Production,
            KsefInvoiceSubmissionStatus::Accepted,
            ['ksef_number' => $this->validKsefNumber(self::SELLER_NIP, '0200001AF629')],
        );

        $reference = $this->resolve($target, KsefEnvironment::Production);

        $this->assertSame([[
            'correction_id' => $first->getKey(),
            'submission_id' => $firstSubmission->getKey(),
            'ksef_number' => $firstSubmission->ksef_number,
        ]], $reference->precedingCorrections);
    }

    public function test_demo_acceptance_of_previous_correction_never_satisfies_production_chain(): void
    {
        $root = $this->rootInvoice();
        $this->submission($root, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::Accepted);
        [$first, $target] = $this->correctionChain($root, 2);
        $this->submission($first, KsefEnvironment::Demo, KsefInvoiceSubmissionStatus::Accepted);

        $this->expectDomainError(
            'ksef_fa3_correction_previous_ksef_environment_mismatch',
            fn () => $this->resolve($target, KsefEnvironment::Production),
        );
    }

    public function test_third_correction_checks_and_returns_all_preceding_corrections(): void
    {
        $root = $this->rootInvoice();
        $this->submission($root, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::Accepted);
        [$first, $second, $target] = $this->correctionChain($root, 3);
        $firstSubmission = $this->submission($first, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::Accepted);
        $secondSubmission = $this->submission(
            $second,
            KsefEnvironment::Production,
            KsefInvoiceSubmissionStatus::Accepted,
            ['ksef_number' => $this->validKsefNumber(self::SELLER_NIP, '0300001AF629')],
        );

        $reference = $this->resolve($target, KsefEnvironment::Production);

        $this->assertSame(
            [$first->getKey(), $second->getKey()],
            array_column($reference->precedingCorrections, 'correction_id'),
        );
        $this->assertSame(
            [$firstSubmission->getKey(), $secondSubmission->getKey()],
            array_column($reference->precedingCorrections, 'submission_id'),
        );
    }

    public function test_gap_in_an_earlier_correction_fails_even_when_immediate_previous_is_accepted(): void
    {
        $root = $this->rootInvoice();
        $this->submission($root, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::Accepted);
        [$first, $second, $target] = $this->correctionChain($root, 3);
        $this->submission($second, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::Accepted);

        $exception = $this->expectDomainError(
            'ksef_fa3_correction_previous_ksef_not_accepted',
            fn () => $this->resolve($target, KsefEnvironment::Production),
        );

        $this->assertSame($first->getKey(), $exception->metadata()['previous_correction_id']);
    }

    public function test_multiple_accepted_previous_submissions_are_ambiguous(): void
    {
        $root = $this->rootInvoice();
        $this->submission($root, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::Accepted);
        [$first, $target] = $this->correctionChain($root, 2);
        $this->submission($first, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::Accepted);
        $this->submission(
            $first,
            KsefEnvironment::Production,
            KsefInvoiceSubmissionStatus::Accepted,
            ['ksef_number' => $this->validKsefNumber(self::SELLER_NIP, '0400001AF629')],
        );

        $this->expectDomainError(
            'ksef_fa3_correction_previous_ksef_ambiguous',
            fn () => $this->resolve($target, KsefEnvironment::Production),
        );
    }

    public function test_invalid_previous_reference_fails_closed(): void
    {
        $root = $this->rootInvoice();
        $this->submission($root, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::Accepted);
        [$first, $target] = $this->correctionChain($root, 2);
        $this->submission(
            $first,
            KsefEnvironment::Production,
            KsefInvoiceSubmissionStatus::Accepted,
            [
                'seller_nip' => '5265877635',
                'ksef_number' => $this->validKsefNumber('5265877635', '0500001AF629'),
            ],
        );

        $this->expectDomainError(
            'ksef_fa3_correction_previous_ksef_reference_invalid',
            fn () => $this->resolve($target, KsefEnvironment::Production),
        );
    }

    public function test_invalid_target_or_inconsistent_chain_fails_closed(): void
    {
        $root = $this->rootInvoice();

        $this->expectDomainError(
            'ksef_fa3_correction_source_invalid',
            fn () => $this->resolve($root, KsefEnvironment::Production),
        );

        [$target] = $this->correctionChain($root, 1);
        $target->forceFill(['previous_correction_id' => $target->getKey()])->saveQuietly();

        $this->expectDomainError(
            'ksef_fa3_correction_source_invalid',
            fn () => $this->resolve($target->refresh(), KsefEnvironment::Production),
        );
    }

    public function test_it_resolves_an_explicit_outside_production_root(): void
    {
        $root = $this->rootInvoice();
        $provenance = $this->markOutside($root, KsefEnvironment::Production);
        [$target] = $this->correctionChain($root, 1);

        $reference = $this->resolve($target, KsefEnvironment::Production);

        $this->assertSame(KsefFa3CorrectionRootReferenceType::OutsideKsef, $reference->rootReferenceType);
        $this->assertSame($provenance->getKey(), $reference->rootProvenanceId);
        $this->assertNull($reference->rootSubmissionId);
        $this->assertNull($reference->rootKsefNumber);
        $this->assertSame('FV/100/2026', $reference->rootInvoiceNumber);
        $this->assertSame('2026-08-20', $reference->correctedInvoiceIssueDate);
        $this->assertSame([], $reference->precedingCorrections);
    }

    public function test_outside_provenance_never_falls_back_across_environments(): void
    {
        $root = $this->rootInvoice();
        $this->markOutside($root, KsefEnvironment::Demo);
        [$target] = $this->correctionChain($root, 1);

        $this->expectDomainError(
            'ksef_fa3_correction_source_ksef_unresolved',
            fn () => $this->resolve($target, KsefEnvironment::Production),
        );
    }

    public function test_outside_production_ignores_accepted_demo(): void
    {
        $root = $this->rootInvoice();
        $provenance = $this->markOutside($root, KsefEnvironment::Production);
        $this->submission($root, KsefEnvironment::Demo, KsefInvoiceSubmissionStatus::Accepted);
        [$target] = $this->correctionChain($root, 1);

        $reference = $this->resolve($target, KsefEnvironment::Production);

        $this->assertSame(KsefFa3CorrectionRootReferenceType::OutsideKsef, $reference->rootReferenceType);
        $this->assertSame($provenance->getKey(), $reference->rootProvenanceId);
    }

    public function test_accepted_production_ignores_outside_demo(): void
    {
        $root = $this->rootInvoice();
        $this->markOutside($root, KsefEnvironment::Demo);
        $accepted = $this->submission(
            $root,
            KsefEnvironment::Production,
            KsefInvoiceSubmissionStatus::Accepted,
        );
        [$target] = $this->correctionChain($root, 1);

        $reference = $this->resolve($target, KsefEnvironment::Production);

        $this->assertSame(KsefFa3CorrectionRootReferenceType::Ksef, $reference->rootReferenceType);
        $this->assertSame($accepted->getKey(), $reference->rootSubmissionId);
        $this->assertNull($reference->rootProvenanceId);
    }

    #[DataProvider('conflictingSubmissionStatusProvider')]
    public function test_any_same_environment_submission_conflicts_with_outside_provenance(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $root = $this->rootInvoice();
        $this->directOutside($root, KsefEnvironment::Production);
        $this->submission($root, KsefEnvironment::Production, $status);
        [$target] = $this->correctionChain($root, 1);

        $exception = $this->expectDomainError(
            'ksef_fa3_correction_source_provenance_conflict',
            fn () => $this->resolve($target, KsefEnvironment::Production),
        );

        $this->assertSame('submission_history_exists', $exception->metadata()['reason']);
        $this->assertSame([$status->value], $exception->metadata()['submission_statuses']);
    }

    public function test_second_correction_of_outside_root_still_requires_accepted_previous_correction(): void
    {
        $root = $this->rootInvoice();
        $this->markOutside($root, KsefEnvironment::Production);
        [$first, $target] = $this->correctionChain($root, 2);
        $firstSubmission = $this->submission(
            $first,
            KsefEnvironment::Production,
            KsefInvoiceSubmissionStatus::Accepted,
        );

        $reference = $this->resolve($target, KsefEnvironment::Production);

        $this->assertSame(KsefFa3CorrectionRootReferenceType::OutsideKsef, $reference->rootReferenceType);
        $this->assertSame([[
            'correction_id' => $first->getKey(),
            'submission_id' => $firstSubmission->getKey(),
            'ksef_number' => $firstSubmission->ksef_number,
        ]], $reference->precedingCorrections);
    }

    public function test_outside_root_does_not_fill_a_previous_correction_gap(): void
    {
        $root = $this->rootInvoice();
        $this->markOutside($root, KsefEnvironment::Production);
        [$first, $target] = $this->correctionChain($root, 2);

        $exception = $this->expectDomainError(
            'ksef_fa3_correction_previous_ksef_not_accepted',
            fn () => $this->resolve($target, KsefEnvironment::Production),
        );

        $this->assertSame($first->getKey(), $exception->metadata()['previous_correction_id']);
    }

    public static function nonProductionEnvironmentProvider(): array
    {
        return [
            'demo' => [KsefEnvironment::Demo],
            'test' => [KsefEnvironment::Test],
        ];
    }

    public static function nonAcceptedStatusProvider(): array
    {
        $statuses = array_filter(
            KsefInvoiceSubmissionStatus::cases(),
            static fn (KsefInvoiceSubmissionStatus $status): bool => $status !== KsefInvoiceSubmissionStatus::Accepted,
        );

        return array_combine(
            array_map(static fn (KsefInvoiceSubmissionStatus $status): string => $status->value, $statuses),
            array_map(static fn (KsefInvoiceSubmissionStatus $status): array => [$status], $statuses),
        );
    }

    public static function conflictingSubmissionStatusProvider(): array
    {
        return array_combine(
            array_map(static fn (KsefInvoiceSubmissionStatus $status): string => $status->value, KsefInvoiceSubmissionStatus::cases()),
            array_map(static fn (KsefInvoiceSubmissionStatus $status): array => [$status], KsefInvoiceSubmissionStatus::cases()),
        );
    }

    public static function invalidRootReferenceProvider(): array
    {
        return [
            'missing KSeF number' => [['ksef_number' => null]],
            'invalid KSeF number checksum' => [['ksef_number' => self::SELLER_NIP.'-20260819-0100001AF629-00']],
            'KSeF number with surrounding whitespace' => [[
                'ksef_number' => ' '.self::validKsefNumberStatic(self::SELLER_NIP, '0100001AF629').' ',
            ]],
            'seller mismatch' => [[
                'seller_nip' => '5265877635',
                'ksef_number' => self::validKsefNumberStatic('5265877635', '0100001AF629'),
            ]],
        ];
    }

    private const SELLER_NIP = '9876543210';

    private function rootInvoice(): Invoice
    {
        $order = $this->createDocumentOrder([
            'total_gross' => '123.00',
            'paid_amount' => '0.00',
            'delivery_cost_gross' => '0.00',
        ]);
        $this->createDocumentItem($order, [
            'unit_price_gross' => '123.00',
            'total_price_gross' => '123.00',
        ]);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(attributes: ['include_shipping' => false]),
            $this->documentContext('2026-08-20 10:00:00'),
        );
        $seller = $invoice->seller_snapshot;
        $seller['tax_id'] = self::SELLER_NIP;
        $invoice->forceFill([
            'number' => 'FV/100/2026',
            'issue_date' => '2026-08-20',
            'seller_snapshot' => $seller,
        ])->saveQuietly();

        return $invoice->refresh();
    }

    /** @return list<Invoice> */
    private function correctionChain(Invoice $root, int $length): array
    {
        $corrections = [];
        $previous = null;

        for ($position = 1; $position <= $length; $position++) {
            if ($previous instanceof Invoice) {
                $previous->forceFill(['finalized_at' => '2026-08-21 12:00:00'])->saveQuietly();
            }

            $correction = Invoice::query()->create([
                'order_id' => $root->order_id,
                'invoice_series_id' => $this->createDocumentSeries(InvoiceDocumentType::Correction)->getKey(),
                'document_type' => InvoiceDocumentType::Correction,
                'status' => InvoiceDocumentStatus::Issued,
                'number' => 'KOR/'.$position.'/2026',
                'issue_date' => '2026-08-21',
                'issued_at' => '2026-08-21 10:00:00',
                'corrected_invoice_id' => $root->getKey(),
                'previous_correction_id' => $previous?->getKey(),
                'correction_reason' => 'Test referencji KSeF',
                'seller_snapshot' => $root->seller_snapshot,
                'currency' => 'PLN',
                'total_net' => '0.00',
                'total_vat' => '0.00',
                'total_gross' => '0.00',
                'paid_amount' => '0.00',
                'amount_due' => '0.00',
            ]);
            $corrections[] = $correction;
            $previous = $correction;
        }

        OrderDocumentSlot::query()->create([
            'order_id' => $root->order_id,
            'document_type' => InvoiceDocumentType::Correction,
            'invoice_id' => $previous?->getKey(),
        ]);

        return $corrections;
    }

    private function submission(
        Invoice $document,
        KsefEnvironment $environment,
        KsefInvoiceSubmissionStatus $status,
        array $attributes = [],
    ): KsefInvoiceSubmission {
        $attempt = ((int) KsefInvoiceSubmission::query()
            ->where('invoice_id', $document->getKey())
            ->where('environment', $environment->value)
            ->max('attempt_number')) + 1;

        return KsefInvoiceSubmission::query()->create(array_replace([
            'invoice_id' => $document->getKey(),
            'environment' => $environment,
            'context_nip' => self::SELLER_NIP,
            'seller_nip' => self::SELLER_NIP,
            'attempt_number' => $attempt,
            'status' => $status,
            'schema_id' => 'FA3',
            'generated_at' => CarbonImmutable::parse('2026-08-29T10:00:00Z'),
            'payload_xml' => '<Faktura/>',
            'invoice_hash' => base64_encode(hash('sha256', '<Faktura/>', true)),
            'invoice_size' => strlen('<Faktura/>'),
            'ksef_number' => $status === KsefInvoiceSubmissionStatus::Accepted
                ? $this->validKsefNumber(self::SELLER_NIP, '0100001AF629')
                : null,
        ], $attributes));
    }

    private function markOutside(
        Invoice $invoice,
        KsefEnvironment $environment,
    ): KsefInvoiceProvenance {
        if (! $invoice->isFinalized()) {
            $invoice = app(InvoiceFinalizationService::class)->finalize($invoice);
        }

        return app(KsefInvoiceProvenanceService::class)->markOutsideKsef($invoice, $environment);
    }

    private function directOutside(
        Invoice $invoice,
        KsefEnvironment $environment,
    ): KsefInvoiceProvenance {
        return KsefInvoiceProvenance::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => $environment,
            'provenance' => KsefInvoiceProvenanceType::OutsideKsef,
            'recorded_at' => now(),
        ]);
    }

    private function resolve(
        Invoice $correction,
        KsefEnvironment $environment,
    ): KsefFa3CorrectionSourceReference {
        return app(KsefFa3CorrectionSourceReferenceResolver::class)->resolve($correction, $environment);
    }

    private function validKsefNumber(string $sellerNip, string $reference): string
    {
        return self::validKsefNumberStatic($sellerNip, $reference);
    }

    private static function validKsefNumberStatic(string $sellerNip, string $reference): string
    {
        $base = $sellerNip.'-20260819-'.$reference;
        $checksum = 0;
        foreach (str_split($base) as $character) {
            $checksum ^= ord($character);
            for ($bit = 0; $bit < 8; $bit++) {
                $checksum = ($checksum & 0x80) !== 0
                    ? (($checksum << 1) ^ 0x07) & 0xFF
                    : ($checksum << 1) & 0xFF;
            }
        }

        return $base.'-'.strtoupper(str_pad(dechex($checksum), 2, '0', STR_PAD_LEFT));
    }

    private function expectDomainError(string $code, callable $operation): InvoiceDomainException
    {
        try {
            $operation();
            $this->fail('Expected domain error '.$code.'.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());

            return $exception;
        }
    }
}
