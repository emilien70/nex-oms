<?php

namespace Tests\Feature\Ksef;

use Illuminate\Database\Connection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus as Status;
use Modules\Ksef\Events\KsefInvoiceAccepted;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefAccessTokenManager;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefSubmissionExecution;
use Modules\Ksef\Services\KsefSubmissionRecoveryService;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Ksef\CreatesSubmissionRecoveryScenario;
use Tests\Support\KsefOnlineSessionApiFake;
use Tests\TestCase;

class KsefSubmissionTransactionBoundaryTest extends TestCase
{
    use CreatesSubmissionRecoveryScenario;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/ksef-transaction-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        touch($this->directory.'/isolated.sqlite');
        config()->set('database.connections.sqlite.database', $this->directory.'/isolated.sqlite');
        DB::purge('sqlite');
        Http::preventStrayRequests();
        Http::fake([]);
        Queue::fake();
        Event::fake([KsefInvoiceAccepted::class]);
        Artisan::call('migrate', ['--force' => true]);
        DB::statement('CREATE TABLE guard_controls (id INTEGER PRIMARY KEY)');
    }

    protected function tearDown(): void
    {
        DB::disconnect('submission_writer');
        DB::disconnect('sqlite');
        $path = realpath($this->directory);
        if ($path !== false && str_starts_with(basename($path), 'ksef-transaction-test-')
            && dirname($path) === realpath(sys_get_temp_dir())) {
            File::deleteDirectory($path);
        }
        parent::tearDown();
    }

    #[DataProvider('transactions')]
    public function test_entry_rejects_real_transaction_without_taking_ownership(
        KsefEnvironment $environment, string $kind, string $entry,
    ): void {
        config()->set('app.debug', false);
        $submission = $this->preparing($environment)->fresh();
        $before = $submission->getRawOriginal();
        $writer = $submission->getConnection();
        $this->mock(KsefAccessTokenManager::class)->shouldNotReceive('getValidAccessToken');
        $operation = function () use ($writer, $submission, $before, $entry, $kind): void {
            $level = $writer->transactionLevel();
            $this->assertSame($kind === 'pdo' ? 0 : ($kind === 'nested' ? 2 : 1), $level);
            $writer->table('guard_controls')->insert(['id' => 1]);
            $this->assertBlocked(fn () => app(KsefInvoiceSubmissionService::class)->{$entry}($submission));
            $this->assertSame($level, $writer->transactionLevel());
            $this->assertTrue($writer->getPdo()->inTransaction());
            $this->assertSame(1, $writer->table('guard_controls')->count());
            $observer = new PDO('sqlite:'.$this->directory.'/isolated.sqlite');
            $this->assertSame(0, (int) $observer->query('SELECT COUNT(*) FROM guard_controls')->fetchColumn());
            $this->assertSame($before, $submission->fresh()->getRawOriginal());
        };

        try {
            if ($kind === 'callback') {
                try {
                    DB::transaction(function () use ($operation): void {
                        $operation();
                        throw new \RuntimeException('FAKE_CALLER_ROLLBACK');
                    });
                } catch (\RuntimeException $exception) {
                    $this->assertSame('FAKE_CALLER_ROLLBACK', $exception->getMessage());
                }
            } elseif ($kind === 'pdo') {
                $writer->getPdo()->beginTransaction();
                $operation();
            } else {
                $writer->beginTransaction();
                if ($kind === 'nested') {
                    $writer->beginTransaction();
                }
                $operation();
            }
        } finally {
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack(0);
            } elseif ($writer->getPdo()->inTransaction()) {
                $writer->getPdo()->rollBack();
            }
        }

        $this->assertSame(0, $writer->table('guard_controls')->count());
        $this->assertSame($before, $submission->fresh()->getRawOriginal());
        $this->assertNoExternalEffects();
    }

    public static function transactions(): array
    {
        $cases = [];
        foreach (KsefEnvironment::cases() as $environment) {
            foreach (['laravel', 'callback', 'nested', 'pdo'] as $kind) {
                foreach (['submit', 'submitOffline', 'submitTechnicalCorrection'] as $entry) {
                    $cases[$environment->value.' '.$kind.' '.$entry] = [$environment, $kind, $entry];
                }
            }
        }

        return $cases;
    }

    public function test_direct_consume_post_rejects_transaction_without_mutating_evidence(): void
    {
        $submission = $this->opened($this->preparing());
        $before = $submission->getRawOriginal();
        DB::beginTransaction();
        try {
            $this->assertBlocked(fn () => app(KsefSubmissionExecution::class)->consumePost(
                $submission, $submission->execution_owner, $this->requestFor($submission),
            ));
            $this->assertTrue(DB::getPdo()->inTransaction());
            $this->assertSame($before, $submission->fresh()->getRawOriginal());
        } finally {
            DB::rollBack();
        }
        $this->assertNoExternalEffects();
    }

    public function test_laravel_transaction_level_still_rejects_when_pdo_is_inconsistent(): void
    {
        $submission = $this->preparing();
        DB::beginTransaction();
        DB::getPdo()->rollBack();
        try {
            $this->assertSame(1, DB::transactionLevel());
            $this->assertFalse(DB::getPdo()->inTransaction());
            $this->assertBlocked(fn () => app(KsefInvoiceSubmissionService::class)->submit($submission));
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertNoExternalEffects();
    }

    #[DataProvider('stateFailures')]
    public function test_state_read_failure_is_safe_and_fail_closed(string $failure): void
    {
        config()->set(['app.debug' => false, 'ksef.invoice_submission_enabled' => true]);
        $submission = \Mockery::mock(KsefInvoiceSubmission::class)->makePartial();
        $connection = \Mockery::mock(Connection::class);
        if ($failure === 'connection') {
            $submission->shouldReceive('getConnection')->andThrow(new \RuntimeException('FAKE_PRIVATE_DSN'));
        } else {
            $submission->shouldReceive('getConnection')->andReturn($connection);
            if ($failure === 'level') {
                $connection->shouldReceive('transactionLevel')->andThrow(new \RuntimeException('FAKE_PRIVATE_DSN'));
            } else {
                $connection->shouldReceive('transactionLevel')->andReturn(0);
                $pdo = new class extends PDO
                {
                    public function __construct() {}

                    public function inTransaction(): bool
                    {
                        throw new \RuntimeException('FAKE_PRIVATE_DSN');
                    }
                };
                $connection->shouldReceive('getPdo')->andReturn($pdo);
            }
        }
        $this->mock(KsefAccessTokenManager::class)->shouldNotReceive('getValidAccessToken');
        $exception = $this->assertBlocked(
            fn () => app(KsefInvoiceSubmissionService::class)->submit($submission),
            'ksef_submission_transaction_state_unavailable',
        );
        $this->assertStringNotContainsString('FAKE_PRIVATE_DSN', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
        $this->assertNull($exception->systemWarning);
        $this->assertNoExternalEffects();
    }

    public static function stateFailures(): array
    {
        return [['connection'], ['level'], ['pdo']];
    }

    #[DataProvider('lateTransactions')]
    public function test_late_transaction_blocks_post_without_cleanup_or_reset(bool $afterFence): void
    {
        $submission = $this->preparing();
        $this->mock(KsefAccessTokenManager::class)->shouldReceive('getValidAccessToken')->once()->andReturn('FAKE-ACCESS');
        $fake = new KsefOnlineSessionApiFake;
        Http::fake(fn (Request $request) => $fake($request));
        $execution = new KsefSubmissionExecution;
        $snapshot = null;
        $this->partialMock(KsefSubmissionExecution::class)->shouldReceive('consumePost')->once()
            ->andReturnUsing(function ($current, $owner, $request) use ($execution, $afterFence, &$snapshot) {
                if ($afterFence) {
                    $current = $execution->consumePost($current, $owner, $request);
                }
                $snapshot = $current->getRawOriginal();
                $current->getConnection()->beginTransaction();

                return $afterFence ? $current : $execution->consumePost($current, $owner, $request);
            });
        try {
            $this->assertBlocked(fn () => app(KsefInvoiceSubmissionService::class)->submit($submission));
            $this->assertTrue(DB::getPdo()->inTransaction());
            $this->assertSame($snapshot, $submission->fresh()->getRawOriginal());
        } finally {
            DB::rollBack();
        }
        $current = $submission->fresh();
        $this->assertSame($snapshot, $current->getRawOriginal());
        $this->assertSame($afterFence, $current->invoice_post_started_at !== null);
        $this->assertSame(Status::SessionOpened, $current->status);
        $this->assertSame(1, $fake->publicKeyCalls);
        $this->assertSame(1, $fake->openCalls);
        $this->assertSame(0, $fake->sendCalls);
        $this->assertSame(0, $fake->closeCalls);
        Http::assertSentCount(2);
        Queue::assertNothingPushed();
        Event::assertNotDispatched(KsefInvoiceAccepted::class);
    }

    public static function lateTransactions(): array
    {
        return ['before fence' => [false], 'after durable fence' => [true]];
    }

    public function test_named_writer_is_checked_and_retained_through_reload_and_post(): void
    {
        $submission = $this->preparing();
        config()->set('database.connections.submission_writer', config('database.connections.sqlite'));
        $submission->setConnection('submission_writer');
        $writer = $submission->getConnection();
        $this->assertNotSame(DB::getPdo(), $writer->getPdo());
        $writer->beginTransaction();
        try {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertBlocked(fn () => app(KsefInvoiceSubmissionService::class)->submit($submission));
            Http::assertNothingSent();
        } finally {
            $writer->rollBack();
        }
        $this->mock(KsefAccessTokenManager::class)->shouldReceive('getValidAccessToken')->once()->andReturn('FAKE-ACCESS');
        $fake = new KsefOnlineSessionApiFake;
        $observer = new PDO('sqlite:'.$this->directory.'/isolated.sqlite');
        $markerWriters = [];
        DB::listen(function ($query) use (&$markerWriters): void {
            if (str_starts_with($query->sql, 'update') && str_contains($query->sql, 'invoice_post_started_at')) {
                $markerWriters[] = $query->connectionName;
            }
        });
        Http::fake(function (Request $request) use ($submission, $writer, $observer, $fake) {
            if (str_ends_with($request->url(), '/invoices')) {
                $this->assertSame(0, $writer->transactionLevel());
                $this->assertFalse($writer->getPdo()->inTransaction());
                $row = $observer->query('SELECT * FROM ksef_invoice_submissions')->fetch(PDO::FETCH_ASSOC);
                $this->assertSame($submission->id, $row['id']);
                $this->assertSame($submission->invoice_hash, $row['invoice_hash']);
                $this->assertSame($fake->openResponse['referenceNumber'], $row['session_reference_number']);
                $this->assertNotNull($row['invoice_post_started_at']);
            }

            return $fake($request);
        });
        $result = app(KsefInvoiceSubmissionService::class)->submit($submission);
        $this->assertSame('submission_writer', $result->getConnectionName());
        $this->assertNotEmpty($markerWriters);
        $this->assertSame(['submission_writer'], array_values(array_unique($markerWriters)));
        $this->assertSame(Status::Submitted, $result->status);
        $this->assertSame(1, $fake->sendCalls);
    }

    public function test_prepare_and_recovery_remain_legal_inside_caller_transaction(): void
    {
        DB::beginTransaction();
        try {
            $submission = $this->preparing();
            $this->assertSame(1, DB::transactionLevel());
            $this->travel(301)->seconds();
            $result = app(KsefSubmissionRecoveryService::class)->apply($submission->id);
            $this->assertTrue($result['applied']);
            $this->assertSame(Status::TechnicalFailed, $submission->fresh()->status);
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        $this->assertNoExternalEffects();
    }

    private function assertBlocked(callable $operation, string $code = 'ksef_submission_transaction_active'): KsefApiException
    {
        try {
            $operation();
            $this->fail('Transport must reject the active or unknown transaction.');
        } catch (KsefApiException $exception) {
            $this->assertSame($code, $exception->safeCode);

            return $exception;
        }
    }

    private function assertNoExternalEffects(): void
    {
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Event::assertNotDispatched(KsefInvoiceAccepted::class);
    }
}
