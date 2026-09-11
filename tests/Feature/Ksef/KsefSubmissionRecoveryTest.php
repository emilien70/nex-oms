<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus as Status;
use Modules\Ksef\Events\KsefInvoiceAccepted;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\KsefAccessTokenManager;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefSubmissionExecution;
use Modules\Ksef\Services\KsefSubmissionRecoveryPolicy;
use Modules\Ksef\Services\KsefSubmissionRecoveryService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Ksef\CreatesSubmissionRecoveryScenario;
use Tests\Support\KsefOnlineSessionApiFake;
use Tests\TestCase;

class KsefSubmissionRecoveryTest extends TestCase
{
    use CreatesSubmissionRecoveryScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        Event::fake([KsefInvoiceAccepted::class]);
    }

    #[DataProvider('prePostCases')]
    public function test_stale_pre_post_recovery_revokes_owner_without_transmission(KsefEnvironment $environment, string $phase): void
    {
        $submission = $this->preparing($environment);
        $execution = app(KsefSubmissionExecution::class);
        $submission = match ($phase) {
            'claimed', 'orphan_session' => $execution->claim($submission),
            'opened' => $this->opened($submission),
            default => $submission,
        };
        // Remote session creation is outside the local record and cannot prove an invoice POST.
        $remote = $phase === 'orphan_session' ? ['session' => 'FAKE-ORPHAN-SESSION', 'invoice_posts' => 0] : [];
        $before = $submission->invoice->getRawOriginal();
        $recovery = app(KsefSubmissionRecoveryService::class);
        $this->assertSame('ACTIVE', $recovery->inspect($submission->id)['decision']);
        $this->assertFalse($recovery->apply($submission->id)['applied']);
        $this->travel(301)->seconds();
        $this->assertSame('BEFORE_POST', $recovery->inspect($submission->id)['decision']);
        $this->assertTrue($recovery->apply($submission->id)['applied']);
        $current = $submission->fresh();
        $this->assertSame(Status::TechnicalFailed, $current->status);
        $this->assertNull($current->execution_owner);
        $this->assertNull($current->invoice_post_started_at);
        $this->assertSame('ksef_submission_interrupted_before_post', $current->safe_error_code);
        $this->assertFalse($recovery->apply($submission->id)['applied']);
        if ($submission->execution_owner !== null) {
            $this->errorCode('ksef_submission_execution_lost', fn () => $execution->touch($submission, $submission->execution_owner));
        }
        $this->assertSame($before, $submission->invoice->fresh()->getRawOriginal());
        $this->assertSame(0, $remote['invoice_posts'] ?? 0);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Event::assertNotDispatched(KsefInvoiceAccepted::class);
    }

    public static function prePostCases(): array
    {
        $cases = [];
        foreach (KsefEnvironment::cases() as $environment) {
            foreach (['prepared', 'claimed', 'orphan_session', 'opened'] as $phase) {
                $cases[$environment->value.' '.$phase] = [$environment, $phase];
            }
        }

        return $cases;
    }

    #[DataProvider('environments')]
    public function test_consumed_boundary_is_irreversible_even_when_no_request_was_made(KsefEnvironment $environment): void
    {
        $submission = $this->opened($this->preparing($environment));
        $execution = app(KsefSubmissionExecution::class);
        $owner = $submission->execution_owner;
        $request = $this->requestFor($submission);
        $submission = $execution->consumePost($submission, $owner, $request);
        $marker = $submission->getRawOriginal('invoice_post_started_at');
        $this->travel(301)->seconds();
        $recovery = app(KsefSubmissionRecoveryService::class);
        $result = $recovery->apply($submission->id);
        $this->assertSame('POST_MAY_HAVE_OCCURRED', $result['decision']);
        $this->assertTrue($result['applied']);
        $this->assertSame(Status::Uncertain, $submission->fresh()->status);
        $this->assertSame('reconcile', $submission->fresh()->follow_up_action);
        $this->assertNotNull($submission->fresh()->next_follow_up_at);
        $this->assertSame($marker, $submission->fresh()->getRawOriginal('invoice_post_started_at'));
        $this->errorCode('ksef_submission_execution_lost', fn () => $execution->touch($submission, $owner));
        $this->errorCode('ksef_submission_state_invalid', fn () => app(KsefInvoiceSubmissionService::class)->submit($submission));
        $this->assertFalse($recovery->apply($submission->id)['applied']);
        Http::assertNothingSent();
    }

    public static function environments(): array
    {
        return array_map(fn ($environment) => [$environment], KsefEnvironment::cases());
    }

    #[DataProvider('reviewCases')]
    public function test_missing_or_inconsistent_evidence_never_unlocks(array $attributes): void
    {
        $submission = $this->preparing();
        $submission->forceFill($attributes)->save();
        $this->travel(301)->seconds();
        $before = $submission->fresh()->getRawOriginal();
        $recovery = app(KsefSubmissionRecoveryService::class);
        $this->assertSame('REVIEW_REQUIRED', $recovery->inspect($submission->id)['decision']);
        $this->assertFalse($recovery->apply($submission->id)['applied']);
        $this->assertSame($before, $submission->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public static function reviewCases(): array
    {
        return [
            'legacy' => [['execution_protocol_version' => null, 'execution_expires_at' => null]],
            'future version' => [['execution_protocol_version' => 99]],
            'hash' => [['invoice_hash' => 'BROKEN']],
            'missing deadline' => [['execution_expires_at' => null]],
            'owner malformed' => [['execution_owner' => 'INVALID']],
            'missing session' => [['status' => Status::SessionOpened]],
            'wrong relation' => [['offline_technical_correction_id' => null, 'offline_issuance_id' => null, 'seller_nip' => null]],
            'unknown reference' => [['invoice_reference_number' => 'FAKE-UNEXPLAINED-REFERENCE']],
            'unexpected MF number' => [['ksef_number' => 'FAKE-UNEXPLAINED-NUMBER']],
            'unexpected MF status' => [['ksef_status_code' => 200]],
        ];
    }

    #[DataProvider('historyCases')]
    public function test_read_only_history_classification_preserves_legacy(Status $status, bool $hasUpo, string $decision, string $reason): void
    {
        $submission = $this->preparing();
        $submission->forceFill(['status' => $status, 'execution_protocol_version' => null])->save();
        $result = app(KsefSubmissionRecoveryPolicy::class)->classify($submission, $hasUpo, true, CarbonImmutable::now('UTC'));
        $this->assertSame(compact('decision', 'reason'), $result);
        $before = $submission->fresh()->getRawOriginal();
        $this->assertFalse(app(KsefSubmissionRecoveryService::class)->apply($submission->id)['applied']);
        $this->assertSame($before, $submission->fresh()->getRawOriginal());
        Event::assertNotDispatched(KsefInvoiceAccepted::class);
        Http::assertNothingSent();
    }

    public static function historyCases(): array
    {
        return [
            [Status::Submitted, false, 'EXISTING_FOLLOW_UP', 'existing_status_follow_up'],
            [Status::Processing, false, 'EXISTING_FOLLOW_UP', 'existing_status_follow_up'],
            [Status::Accepted, false, 'NO_ACTION', 'existing_upo_follow_up'],
            [Status::Accepted, true, 'NO_ACTION', 'terminal_history'],
            [Status::Rejected, false, 'NO_ACTION', 'terminal_history'],
            [Status::TechnicalFailed, false, 'NO_ACTION', 'terminal_history'],
        ];
    }

    public function test_command_is_inspect_by_default_and_apply_is_local_and_idempotent(): void
    {
        $submission = $this->preparing();
        $this->travel(301)->seconds();
        $before = $submission->fresh()->getRawOriginal();
        $this->artisan('ksef:recover-submission', ['--submission' => (string) $submission->id])->assertSuccessful();
        $this->assertSame($before, $submission->fresh()->getRawOriginal());
        $this->artisan('ksef:recover-submission')->assertExitCode(2);
        $this->artisan('ksef:recover-submission', ['--submission' => (string) $submission->id, '--apply' => true])->assertSuccessful();
        $this->assertSame(Status::TechnicalFailed, $submission->fresh()->status);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_wrong_owner_and_invalid_request_cannot_consume_post_and_owner_is_hidden(): void
    {
        $submission = $this->opened($this->preparing());
        $this->assertArrayNotHasKey('execution_owner', $submission->toArray());
        $execution = app(KsefSubmissionExecution::class);
        $this->errorCode('ksef_submission_execution_lost', fn () => $execution->touch($submission, str_repeat('b', 64)));
        $request = $this->requestFor($submission);
        $request['invoiceHash'] = 'BROKEN';
        $this->errorCode('ksef_submission_request_inconsistent', fn () => $execution->consumePost($submission, $submission->execution_owner, $request));
        $this->assertNull($submission->fresh()->invoice_post_started_at);
        Http::assertNothingSent();
    }

    #[DataProvider('lateResults')]
    public function test_late_send_response_or_exception_cannot_overwrite_recovery_or_terminal_state(bool $failure, bool $terminal, Status $terminalStatus = Status::Accepted): void
    {
        $submission = $this->preparing();
        $this->fakeAccess();
        $fake = new KsefOnlineSessionApiFake;
        Http::fake(function (Request $request) use ($fake, $submission, $failure, $terminal, $terminalStatus) {
            $response = $fake($request);
            if (str_ends_with($request->url(), '/invoices') && $request->method() === 'POST') {
                $this->assertNotNull($submission->fresh()->invoice_post_started_at);
                $this->travel(301)->seconds();
                $this->assertTrue(app(KsefSubmissionRecoveryService::class)->apply($submission->id)['applied']);
                if ($terminal) {
                    $submission->fresh()->forceFill(['status' => $terminalStatus, 'ksef_number' => $terminalStatus === Status::Accepted ? 'FAKE-TERMINAL-NUMBER' : null])->save();
                }
                if ($failure) {
                    throw new KsefApiException('Synthetic failure', 'network_error');
                }
            }

            return $response;
        });
        $this->errorCode($failure ? 'network_error' : 'ksef_invoice_delivery_uncertain', fn () => app(KsefInvoiceSubmissionService::class)->submit($submission));
        $this->assertSame($terminal ? $terminalStatus : Status::Uncertain, $submission->fresh()->status);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertNull($submission->fresh()->invoice_reference_number);
        $this->assertSame($terminal && $terminalStatus === Status::Accepted ? 'FAKE-TERMINAL-NUMBER' : null, $submission->fresh()->ksef_number);
        Event::assertNotDispatched(KsefInvoiceAccepted::class);
    }

    public static function lateResults(): array
    {
        return [[false, false], [true, false], [false, true], [true, true], [false, true, Status::Rejected], [true, true, Status::Rejected]];
    }

    #[DataProvider('responseWriteFailures')]
    public function test_response_persistence_failure_keeps_durable_fence_and_reconciles_same_attempt(bool $uncertainWriteFails): void
    {
        $submission = $this->preparing();
        $this->fakeAccess();
        $fake = new KsefOnlineSessionApiFake;
        Http::fake(fn (Request $request) => $fake($request));
        // A local trigger fails only the response write, not the separate fake remote effect.
        $states = $uncertainWriteFails ? "'submitted', 'uncertain'" : "'submitted'";
        DB::unprepared("CREATE TEMP TRIGGER fail_submitted BEFORE UPDATE OF status ON ksef_invoice_submissions WHEN NEW.status IN ($states) BEGIN SELECT RAISE(ABORT, 'synthetic persistence failure'); END");
        try {
            $this->errorCode('ksef_invoice_delivery_uncertain', fn () => app(KsefInvoiceSubmissionService::class)->submit($submission));
        } finally {
            DB::unprepared('DROP TRIGGER fail_submitted');
        }
        $this->assertSame(1, $fake->sendCalls);
        $this->assertNotNull($submission->fresh()->invoice_post_started_at);
        $this->assertNotNull($submission->fresh()->session_reference_number);
        $this->assertNull($submission->fresh()->invoice_reference_number);
        if ($uncertainWriteFails) {
            $this->assertSame(Status::SessionOpened, $submission->fresh()->status);
            $this->travel(301)->seconds();
            $this->assertSame('POST_MAY_HAVE_OCCURRED', app(KsefSubmissionRecoveryService::class)->apply($submission->id)['decision']);
        }
        $this->assertSame(Status::Uncertain, $submission->fresh()->status);
        $fake->sessionInvoicesResponse = ['invoices' => [[
            'referenceNumber' => 'FAKE-RECOVERED-REFERENCE', 'invoiceHash' => $submission->invoice_hash,
            'status' => ['code' => 150],
        ]]];
        $result = app(KsefInvoiceSubmissionService::class)->reconcile($submission);
        $this->assertSame(Status::Processing, $result->status);
        $this->assertSame('FAKE-RECOVERED-REFERENCE', $result->invoice_reference_number);
        $this->assertSame(1, $fake->sendCalls);
    }

    public static function responseWriteFailures(): array
    {
        return ['response write' => [false], 'response and uncertainty writes' => [true]];
    }

    public function test_legacy_preparing_is_not_claimed_or_transmitted(): void
    {
        $submission = $this->preparing();
        $submission->forceFill(['execution_protocol_version' => null, 'execution_expires_at' => null])->save();
        $this->errorCode('ksef_submission_execution_review_required', fn () => app(KsefInvoiceSubmissionService::class)->submit($submission));
        $this->assertSame(Status::Preparing, $submission->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_failed_boundary_write_prevents_invoice_post(): void
    {
        $submission = $this->preparing();
        $this->fakeAccess();
        $fake = new KsefOnlineSessionApiFake;
        Http::fake(fn (Request $request) => $fake($request));
        DB::unprepared("CREATE TEMP TRIGGER fail_boundary BEFORE UPDATE OF invoice_post_started_at ON ksef_invoice_submissions BEGIN SELECT RAISE(ABORT, 'synthetic boundary failure'); END");
        try {
            $this->errorCode('ksef_submission_post_boundary_persistence_failed', fn () => app(KsefInvoiceSubmissionService::class)->submit($submission));
        } finally {
            DB::unprepared('DROP TRIGGER fail_boundary');
        }
        $this->assertSame(0, $fake->sendCalls);
        $this->assertNull($submission->fresh()->invoice_post_started_at);
        $this->assertSame(Status::SessionOpened, $submission->fresh()->status);
        $this->travel(301)->seconds();
        $this->assertSame('BEFORE_POST', app(KsefSubmissionRecoveryService::class)->apply($submission->id)['decision']);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_midnight_does_not_change_frozen_date_and_recovery_does_not_resend(): void
    {
        $submission = $this->preparing();
        $xml = $submission->payload_xml;
        $issueDate = $submission->invoice->issue_date->format('Y-m-d');
        $this->travel(1)->days();
        $this->assertSame('BEFORE_POST', app(KsefSubmissionRecoveryService::class)->apply($submission->id)['decision']);
        $this->assertSame($xml, $submission->fresh()->payload_xml);
        $this->assertSame($issueDate, $submission->invoice->fresh()->issue_date->format('Y-m-d'));
        Http::assertNothingSent();
    }

    public function test_execution_timestamps_preserve_exact_utc_instants(): void
    {
        $submission = $this->preparing();
        $this->assertSame('2026-08-19 10:35:00', $submission->getRawOriginal('execution_expires_at'));
        $submission = $this->opened($submission);
        $submission = app(KsefSubmissionExecution::class)->consumePost($submission, $submission->execution_owner, $this->requestFor($submission));
        $this->assertSame('2026-08-19 10:30:00', $submission->getRawOriginal('invoice_post_started_at'));
        $this->assertSame('UTC', $submission->invoice_post_started_at->tzName);
        $this->travel(301)->seconds();
        app(KsefSubmissionRecoveryService::class)->apply($submission->id);
        $this->assertSame('2026-08-19 10:35:01', $submission->fresh()->getRawOriginal('recovered_at'));
        $this->assertSame('UTC', $submission->fresh()->recovered_at->tzName);
    }

    public function test_wrong_invoice_relation_is_review_required_without_unlock(): void
    {
        $submission = $this->preparing();
        $other = $this->preparing();
        $other->invoice->forceFill(['number' => 'FAKE-DIFFERENT-NUMBER'])->save();
        $submission->forceFill(['invoice_id' => $other->invoice_id, 'attempt_number' => 2])->save();
        $this->travel(301)->seconds();
        $this->assertSame('REVIEW_REQUIRED', app(KsefSubmissionRecoveryService::class)->apply($submission->id)['decision']);
        $this->assertSame(Status::Preparing, $submission->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_disabled_gate_blocks_send_before_claim_and_http(): void
    {
        $submission = $this->preparing();
        config()->set('ksef.invoice_submission_enabled', false);
        $this->errorCode('ksef_submission_disabled', fn () => app(KsefInvoiceSubmissionService::class)->submit($submission));
        $this->assertNull($submission->fresh()->execution_owner);
        Http::assertNothingSent();
    }

    private function fakeAccess(): void
    {
        $this->mock(KsefAccessTokenManager::class)->shouldReceive('getValidAccessToken')->andReturn('FAKE-ACCESS');
    }

    private function errorCode(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected controlled KSeF failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame($code, $exception->safeCode);
        }
    }
}
