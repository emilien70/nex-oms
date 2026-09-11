<?php

// Standalone child: no operator environment, database, credentials, storage or network.
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus as Status;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefSubmissionExecution;
use Modules\Ksef\Services\KsefSubmissionRecoveryService;

require dirname(__DIR__, 3).'/vendor/autoload.php';
[$script, $directory, $id, $name, $stage, $now] = $argv;
$realDirectory = realpath($directory);
if ($realDirectory === false || ! str_starts_with(basename($realDirectory), 'ksef-recovery-test-')
    || ! is_file($realDirectory.'/isolated.sqlite')) {
    exit(90);
}
$variables = [
    'APP_ENV' => 'testing', 'APP_KEY' => 'base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE=',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $realDirectory.'/isolated.sqlite', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
    'LOG_CHANNEL' => 'null', 'APP_CONFIG_CACHE' => $realDirectory.'/absent-config.php',
    'LARAVEL_STORAGE_PATH' => $realDirectory.'/storage',
];
foreach ($variables as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->useEnvironmentPath($realDirectory);
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
Http::fake(fn () => throw new RuntimeException('Network forbidden in recovery process.'));
Queue::fake();
DB::connection()->getPdo()->setAttribute(PDO::ATTR_TIMEOUT, 2);
CarbonImmutable::setTestNow(CarbonImmutable::parse($now, 'UTC'));

function barrier(string $directory, string $name): void
{
    file_put_contents($directory.'/'.$name.'.ready', 'ready');
    $deadline = microtime(true) + 30;
    while (! is_file($directory.'/'.$name.'.go')) {
        if (microtime(true) > $deadline) {
            exit(91);
        }
        usleep(10000);
        clearstatcache();
    }
}

try {
    $execution = $app->make(KsefSubmissionExecution::class);
    $submission = KsefInvoiceSubmission::query()->findOrFail((int) $id);
    if ($stage === 'apply') {
        barrier($directory, $name);
        $result = $app->make(KsefSubmissionRecoveryService::class)->apply((int) $id);
    } else {
        if ($stage === 'before_claim') {
            barrier($directory, $name);
        }
        $submission = $execution->claim($submission);
        $owner = $submission->execution_owner;
        $submission = $execution->encryptionMetadata($submission, $owner, [
            'public_key_id' => 'FAKE-KEY', 'encrypted_invoice_hash' => base64_encode(hash('sha256', 'FAKE-CIPHERTEXT', true)),
            'encrypted_invoice_size' => strlen('FAKE-CIPHERTEXT'),
        ]);
        // Independent fake remote ledger survives a local DB rollback or process death.
        file_put_contents($directory.'/'.$name.'.session-opened', 'FAKE-REMOTE-SESSION');
        if ($stage === 'die_after_open') {
            exit(73);
        }
        if ($stage === 'before_session_save') {
            barrier($directory, $name);
        }
        $submission = $execution->phase($submission, $owner, Status::SessionOpened, ['session_reference_number' => 'FAKE-SESSION-'.$name]);
        if ($stage === 'before_post') {
            barrier($directory, $name);
        }
        $submission = $execution->consumePost($submission, $owner, [
            'offlineMode' => false, 'invoiceHash' => $submission->invoice_hash, 'invoiceSize' => $submission->invoice_size,
            'encryptedInvoiceHash' => $submission->encrypted_invoice_hash, 'encryptedInvoiceSize' => $submission->encrypted_invoice_size,
            'encryptedInvoiceContent' => base64_encode('FAKE-CIPHERTEXT'),
        ]);
        if ($stage === 'die_after_post_boundary') {
            exit(73);
        }
        if ($stage === 'after_post') {
            barrier($directory, $name);
        }
        file_put_contents($directory.'/'.$name.'.invoice-post', 'FAKE-REMOTE-REFERENCE');
        $execution->phase($submission, $owner, Status::Submitted, ['invoice_reference_number' => 'FAKE-REMOTE-REFERENCE']);
        $result = ['sent' => true];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (KsefApiException $exception) {
    echo json_encode(['safe_code' => $exception->safeCode], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['error_class' => $exception::class], JSON_THROW_ON_ERROR);
    exit(92);
} finally {
    file_put_contents($directory.'/'.$name.'.cleanup', 'finally');
}
