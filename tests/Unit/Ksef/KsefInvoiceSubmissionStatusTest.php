<?php

namespace Tests\Unit\Ksef;

use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefInvoiceSubmissionLifecyclePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KsefInvoiceSubmissionStatusTest extends TestCase
{
    #[DataProvider('lifecycleCases')]
    public function test_status_has_central_lifecycle_contract(
        KsefInvoiceSubmissionStatus $status,
        bool $terminal,
        bool $refresh,
        bool $reconciliation,
        bool $newAttempt,
    ): void {
        $this->assertSame($terminal, $status->isTerminal());
        $this->assertSame($refresh, $status->allowsStatusRefresh());
        $this->assertSame($reconciliation, $status->allowsReconciliation());
        $this->assertSame($newAttempt, $status->allowsNewAttempt());
        $this->assertSame(! $newAttempt, $status->blocksNewAttempt());
        $this->assertSame($reconciliation, $status->requiresReconciliation());
        $this->assertTrue($status->blocksDocumentEdit());
        $this->assertTrue($status->blocksDocumentDeletion());
    }

    public static function lifecycleCases(): array
    {
        return [
            'preparing' => [KsefInvoiceSubmissionStatus::Preparing, false, false, false, false],
            'session opened' => [KsefInvoiceSubmissionStatus::SessionOpened, false, false, false, false],
            'submitted' => [KsefInvoiceSubmissionStatus::Submitted, false, true, false, false],
            'processing' => [KsefInvoiceSubmissionStatus::Processing, false, true, false, false],
            'accepted' => [KsefInvoiceSubmissionStatus::Accepted, true, false, false, false],
            'rejected' => [KsefInvoiceSubmissionStatus::Rejected, true, false, false, true],
            'technical failed' => [KsefInvoiceSubmissionStatus::TechnicalFailed, true, false, false, true],
            'uncertain' => [KsefInvoiceSubmissionStatus::Uncertain, false, false, true, false],
        ];
    }

    #[DataProvider('transitionCases')]
    public function test_only_declared_status_transitions_are_allowed(
        KsefInvoiceSubmissionStatus $from,
        array $allowed,
    ): void {
        foreach (KsefInvoiceSubmissionStatus::cases() as $to) {
            $this->assertSame(
                in_array($to, $allowed, true),
                $from->canTransitionTo($to),
                $from->value.' -> '.$to->value,
            );
        }
    }

    public static function transitionCases(): array
    {
        return [
            'preparing' => [KsefInvoiceSubmissionStatus::Preparing, [
                KsefInvoiceSubmissionStatus::SessionOpened,
                KsefInvoiceSubmissionStatus::TechnicalFailed,
            ]],
            'session opened' => [KsefInvoiceSubmissionStatus::SessionOpened, [
                KsefInvoiceSubmissionStatus::Submitted,
                KsefInvoiceSubmissionStatus::TechnicalFailed,
                KsefInvoiceSubmissionStatus::Uncertain,
            ]],
            'submitted' => [KsefInvoiceSubmissionStatus::Submitted, [
                KsefInvoiceSubmissionStatus::Processing,
                KsefInvoiceSubmissionStatus::Accepted,
                KsefInvoiceSubmissionStatus::Rejected,
                KsefInvoiceSubmissionStatus::Uncertain,
            ]],
            'processing' => [KsefInvoiceSubmissionStatus::Processing, [
                KsefInvoiceSubmissionStatus::Processing,
                KsefInvoiceSubmissionStatus::Accepted,
                KsefInvoiceSubmissionStatus::Rejected,
                KsefInvoiceSubmissionStatus::Uncertain,
            ]],
            'accepted' => [KsefInvoiceSubmissionStatus::Accepted, []],
            'rejected' => [KsefInvoiceSubmissionStatus::Rejected, []],
            'technical failed' => [KsefInvoiceSubmissionStatus::TechnicalFailed, []],
            'uncertain' => [KsefInvoiceSubmissionStatus::Uncertain, [
                KsefInvoiceSubmissionStatus::Processing,
                KsefInvoiceSubmissionStatus::Accepted,
                KsefInvoiceSubmissionStatus::Rejected,
                KsefInvoiceSubmissionStatus::Uncertain,
            ]],
        ];
    }

    public function test_history_policy_requires_every_previous_attempt_to_be_retry_safe(): void
    {
        $policy = new KsefInvoiceSubmissionLifecyclePolicy;

        $this->assertTrue($policy->allowsNewAttempt(collect()));
        $this->assertTrue($policy->allowsNewAttempt(collect([
            $this->submission(KsefInvoiceSubmissionStatus::Rejected),
            $this->submission(KsefInvoiceSubmissionStatus::TechnicalFailed),
        ])));
        $this->assertFalse($policy->allowsNewAttempt(collect([
            $this->submission(KsefInvoiceSubmissionStatus::Rejected),
            $this->submission(KsefInvoiceSubmissionStatus::Accepted),
        ])));
        $this->assertFalse($policy->allowsNewAttempt(collect([
            $this->submission(KsefInvoiceSubmissionStatus::Uncertain),
        ])));
    }

    private function submission(KsefInvoiceSubmissionStatus $status): KsefInvoiceSubmission
    {
        return new KsefInvoiceSubmission(['status' => $status]);
    }
}
