<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefSubmissionFollowUpPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KsefSubmissionFollowUpPolicyTest extends TestCase
{
    #[DataProvider('backoffCases')]
    public function test_backoff_is_central_and_capped(
        int $completedAttempts,
        int $expectedSeconds,
    ): void {
        $now = CarbonImmutable::parse('2026-08-25 10:00:00');

        $next = app(KsefSubmissionFollowUpPolicy::class)
            ->nextAttemptAt($completedAttempts, now: $now);

        $this->assertSame($expectedSeconds, (int) $now->diffInSeconds($next));
    }

    public static function backoffCases(): array
    {
        return [
            'first due follow-up' => [0, 60],
            'after first background attempt' => [1, 300],
            'after second background attempt' => [2, 900],
            'after third background attempt' => [3, 3600],
            'later attempts stay hourly' => [10, 3600],
        ];
    }

    public function test_retry_after_can_only_delay_next_attempt(): void
    {
        $now = CarbonImmutable::parse('2026-08-25 10:00:00');
        $policy = app(KsefSubmissionFollowUpPolicy::class);

        $this->assertSame(180, (int) $now->diffInSeconds($policy->nextAttemptAt(0, 180, $now)));
        $this->assertSame(300, (int) $now->diffInSeconds($policy->nextAttemptAt(1, 120, $now)));
    }

    #[DataProvider('actionCases')]
    public function test_actions_follow_submission_state(
        KsefInvoiceSubmissionStatus $status,
        bool $hasUpo,
        ?string $expected,
    ): void {
        $submission = new KsefInvoiceSubmission(['status' => $status]);

        $this->assertSame(
            $expected,
            app(KsefSubmissionFollowUpPolicy::class)->action($submission, $hasUpo),
        );
    }

    public static function actionCases(): array
    {
        return [
            'submitted' => [KsefInvoiceSubmissionStatus::Submitted, false, KsefSubmissionFollowUpPolicy::ACTION_STATUS],
            'processing' => [KsefInvoiceSubmissionStatus::Processing, false, KsefSubmissionFollowUpPolicy::ACTION_STATUS],
            'uncertain' => [KsefInvoiceSubmissionStatus::Uncertain, false, KsefSubmissionFollowUpPolicy::ACTION_RECONCILE],
            'accepted without upo' => [KsefInvoiceSubmissionStatus::Accepted, false, KsefSubmissionFollowUpPolicy::ACTION_UPO],
            'accepted with upo' => [KsefInvoiceSubmissionStatus::Accepted, true, null],
            'preparing' => [KsefInvoiceSubmissionStatus::Preparing, false, null],
            'session opened' => [KsefInvoiceSubmissionStatus::SessionOpened, false, null],
            'rejected' => [KsefInvoiceSubmissionStatus::Rejected, false, null],
            'technical failed' => [KsefInvoiceSubmissionStatus::TechnicalFailed, false, null],
        ];
    }

    public function test_attempts_are_preserved_only_for_the_same_follow_up_action(): void
    {
        $policy = app(KsefSubmissionFollowUpPolicy::class);
        $submission = new KsefInvoiceSubmission([
            'follow_up_attempts' => 4,
            'follow_up_action' => KsefSubmissionFollowUpPolicy::ACTION_STATUS,
        ]);

        $this->assertSame(4, $policy->attemptsForAction(
            $submission,
            KsefSubmissionFollowUpPolicy::ACTION_STATUS,
        ));
        $this->assertSame(0, $policy->attemptsForAction(
            $submission,
            KsefSubmissionFollowUpPolicy::ACTION_UPO,
        ));
        $this->assertSame(0, $policy->attemptsForAction($submission, null));
    }

    public function test_only_explicitly_transient_failures_are_retried(): void
    {
        $policy = app(KsefSubmissionFollowUpPolicy::class);

        $this->assertTrue($policy->isTransient(new KsefApiException('safe', 'network_error')));
        $this->assertTrue($policy->isTransient(new KsefApiException('safe', 'http_5xx', 503)));
        $this->assertTrue($policy->isTransient(new KsefApiException('safe', 'ksef_upo_not_available', 404)));
        $this->assertFalse($policy->isTransient(new KsefApiException('safe', 'ksef_upo_hash_mismatch')));
        $this->assertFalse($policy->isTransient(new KsefApiException('safe', 'unauthorized', 401)));
    }
}
