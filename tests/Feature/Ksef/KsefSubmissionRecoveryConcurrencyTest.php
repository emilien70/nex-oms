<?php

namespace Tests\Feature\Ksef;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus as Status;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefAccessTokenManager;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefSubmissionRecoveryService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\Ksef\CreatesSubmissionRecoveryScenario;
use Tests\Support\KsefOnlineSessionApiFake;
use Tests\TestCase;

class KsefSubmissionRecoveryConcurrencyTest extends TestCase
{
    use CreatesSubmissionRecoveryScenario;

    private string $directory;

    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/ksef-recovery-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        touch($this->directory.'/isolated.sqlite');
        config()->set('database.connections.sqlite.database', $this->directory.'/isolated.sqlite');
        DB::purge('sqlite');
        Http::preventStrayRequests();
        Http::fake([]);
        Queue::fake();
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        DB::disconnect('sqlite');
        $path = realpath($this->directory);
        if ($path !== false && str_starts_with(basename($path), 'ksef-recovery-test-')
            && dirname($path) === realpath(sys_get_temp_dir())) {
            File::deleteDirectory($path);
        }
        parent::tearDown();
    }

    public function test_common_transport_commits_post_boundary_before_http(): void
    {
        $submission = $this->preparing();
        $this->mock(KsefAccessTokenManager::class)->shouldReceive('getValidAccessToken')->andReturn('FAKE-ACCESS');
        $observer = new \PDO('sqlite:'.$this->directory.'/isolated.sqlite');
        $fake = new KsefOnlineSessionApiFake;
        Http::fake(function (Request $request) use ($fake, $submission, $observer) {
            $this->assertSame(0, DB::connection()->transactionLevel());
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/invoices')) {
                $query = $observer->prepare('SELECT invoice_post_started_at, session_reference_number FROM ksef_invoice_submissions WHERE id = ?');
                $query->execute([$submission->id]);
                $persisted = $query->fetch(\PDO::FETCH_ASSOC);
                $this->assertNotNull($persisted['invoice_post_started_at']);
                $this->assertNotEmpty($persisted['session_reference_number']);
            }

            return $fake($request);
        });
        $result = app(KsefInvoiceSubmissionService::class)->submit($submission);
        $this->assertSame(Status::Submitted, $result->status);
        $this->assertSame(1, $fake->sendCalls);
    }

    public function test_two_processes_only_one_claim_and_invoice_post(): void
    {
        $submission = $this->preparing();
        $a = $this->start($submission, 'a', 'before_claim');
        $b = $this->start($submission, 'b', 'before_claim');
        $this->ready('a');
        $this->ready('b');
        $this->release('a');
        $this->release('b');
        $results = [$this->finish($a), $this->finish($b)];
        $this->assertCount(1, array_filter($results, fn ($result) => ($result['sent'] ?? false) === true));
        $this->assertCount(1, array_filter($results, fn ($result) => ($result['safe_code'] ?? null) === 'ksef_submission_execution_lost'));
        $this->assertCount(1, glob($this->directory.'/*.invoice-post'));
        $this->assertCount(1, glob($this->directory.'/*.session-opened'));
        $this->assertSame(Status::Submitted, $submission->fresh()->status);
        $this->assertNotNull($submission->fresh()->invoice_post_started_at);
    }

    #[DataProvider('beforePostBarriers')]
    public function test_stopped_before_post_worker_cannot_send_after_recovery(string $stage): void
    {
        $submission = $this->preparing();
        $a = $this->start($submission, 'a', $stage);
        $this->ready('a');
        $this->travel(301)->seconds();
        $this->assertTrue(app(KsefSubmissionRecoveryService::class)->apply($submission->id)['applied']);
        $this->release('a');
        $this->assertSame('ksef_submission_execution_lost', $this->finish($a)['safe_code']);
        $this->assertCount(0, glob($this->directory.'/*.invoice-post'));
        $this->assertSame(Status::TechnicalFailed, $submission->fresh()->status);
    }

    public static function beforePostBarriers(): array
    {
        return [['before_post'], ['before_session_save']];
    }

    public function test_post_boundary_recovery_never_grants_second_post_even_if_original_process_returns(): void
    {
        $submission = $this->preparing();
        $a = $this->start($submission, 'a', 'after_post');
        $this->ready('a');
        $this->assertCount(0, glob($this->directory.'/*.invoice-post'));
        $this->travel(301)->seconds();
        $this->assertSame('POST_MAY_HAVE_OCCURRED', app(KsefSubmissionRecoveryService::class)->apply($submission->id)['decision']);
        $b = $this->start($submission, 'b', 'send');
        $this->assertSame('ksef_submission_execution_lost', $this->finish($b)['safe_code']);
        $this->release('a');
        $this->assertSame('ksef_submission_execution_lost', $this->finish($a)['safe_code']);
        $this->assertCount(1, glob($this->directory.'/*.invoice-post'));
        $this->assertSame(Status::Uncertain, $submission->fresh()->status);
        $this->assertNull($submission->fresh()->invoice_reference_number);
    }

    public function test_two_apply_processes_have_one_effect(): void
    {
        $submission = $this->preparing();
        $a = $this->start($submission, 'a', 'apply', '2026-08-19 10:36:00');
        $b = $this->start($submission, 'b', 'apply', '2026-08-19 10:36:00');
        $this->ready('a');
        $this->ready('b');
        $this->release('a');
        $this->release('b');
        $results = [$this->finish($a), $this->finish($b)];
        $this->assertCount(1, array_filter($results, fn ($result) => ($result['applied'] ?? false) === true));
        $this->assertSame(Status::TechnicalFailed, $submission->fresh()->status);
        $this->assertNotNull($submission->fresh()->recovered_at);
        $this->assertCount(0, glob($this->directory.'/*.invoice-post'));
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_recovery_races_with_post_boundary_without_regranting_a_post(): void
    {
        $submission = $this->preparing();
        $sender = $this->start($submission, 'sender', 'before_post');
        $this->ready('sender');
        $recovery = $this->start($submission, 'recovery', 'apply', '2026-08-19 10:36:00');
        $this->ready('recovery');
        $this->release('sender');
        $this->release('recovery');
        $sendResult = $this->finish($sender);
        $recoveryResult = $this->finish($recovery);
        $current = $submission->fresh();
        $this->assertLessThanOrEqual(1, count(glob($this->directory.'/*.invoice-post')));
        if ($current->invoice_post_started_at === null) {
            $this->assertSame(Status::TechnicalFailed, $current->status);
            $this->assertCount(0, glob($this->directory.'/*.invoice-post'));
            $this->assertSame('ksef_submission_execution_lost', $sendResult['safe_code']);
            $this->assertTrue($recoveryResult['applied']);
        } else {
            $this->assertContains($current->status, [Status::Submitted, Status::Uncertain]);
            $this->assertFalse($current->status->allowsNewAttempt());
        }
        $duplicate = $this->start($submission, 'duplicate', 'send');
        $this->assertSame('ksef_submission_execution_lost', $this->finish($duplicate)['safe_code']);
    }

    #[DataProvider('deaths')]
    public function test_process_death_without_catch_or_finally_preserves_recovery_evidence(string $stage, string $decision, Status $status): void
    {
        $submission = $this->preparing();
        $a = $this->start($submission, 'a', $stage);
        $a->wait();
        $this->assertSame(73, $a->getExitCode(), $a->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/a.cleanup');
        $this->assertFileExists($this->directory.'/a.session-opened');
        $this->travel(301)->seconds();
        $result = app(KsefSubmissionRecoveryService::class)->apply($submission->id);
        $this->assertTrue($result['applied']);
        $this->assertSame($decision, $result['decision']);
        $this->assertSame($status, $submission->fresh()->status);
        $this->assertCount(0, glob($this->directory.'/*.invoice-post'));
        Http::assertNothingSent();
    }

    public static function deaths(): array
    {
        return [
            ['die_after_open', 'BEFORE_POST', Status::TechnicalFailed],
            ['die_after_post_boundary', 'POST_MAY_HAVE_OCCURRED', Status::Uncertain],
        ];
    }

    public function test_additive_migration_preserves_legacy_data_indexes_and_foreign_keys(): void
    {
        $submission = $this->preparing();
        $migration = require database_path('migrations/2026_08_13_089000_add_ksef_submission_execution_protocol.php');
        $migration->down();
        $before = (array) DB::table('ksef_invoice_submissions')->where('id', $submission->id)->first();
        $indexes = DB::select('PRAGMA index_list(ksef_invoice_submissions)');
        $foreignKeys = DB::select('PRAGMA foreign_key_list(ksef_invoice_submissions)');
        $migration->up();
        $after = (array) DB::table('ksef_invoice_submissions')->where('id', $submission->id)->first();
        $this->assertSame($before, array_intersect_key($after, $before));
        foreach (array_diff_key($after, $before) as $value) {
            $this->assertNull($value);
        }
        $this->assertEquals($indexes, DB::select('PRAGMA index_list(ksef_invoice_submissions)'));
        $this->assertEquals($foreignKeys, DB::select('PRAGMA foreign_key_list(ksef_invoice_submissions)'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame('REVIEW_REQUIRED', app(KsefSubmissionRecoveryService::class)->inspect($submission->id)['decision']);
    }

    private function start(KsefInvoiceSubmission $submission, string $name, string $stage, string $now = '2026-08-19 10:30:00'): Process
    {
        $process = new Process([
            PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail',
            base_path('tests/Support/Ksef/submission_recovery_process.php'),
            $this->directory, (string) $submission->id, $name, $stage, $now,
        ], base_path(), timeout: 45);
        $this->children[] = $process;
        $process->start();

        return $process;
    }

    private function ready(string $name): void
    {
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if (is_file($this->directory.'/'.$name.'.ready')) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Child process did not reach its barrier: '.implode(' ', array_map(fn ($p) => $p->getOutput().$p->getErrorOutput(), $this->children)));
    }

    private function release(string $name): void
    {
        touch($this->directory.'/'.$name.'.go');
    }

    private function finish(Process $process): array
    {
        $process->wait();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
