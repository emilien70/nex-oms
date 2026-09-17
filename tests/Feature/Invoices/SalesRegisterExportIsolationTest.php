<?php

namespace Tests\Feature\Invoices;

use DOMDocument;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\JpkV7m3Context;
use Modules\Invoices\Services\JpkV7m3Exporter;
use Modules\Invoices\Services\JpkV7m3SchemaValidator;
use Modules\Invoices\Services\SalesRegisterXlsxExporter;
use Modules\Invoices\Services\SalesRegisterXmlExporter;
use Modules\Invoices\ValueObjects\SalesRegisterFilters;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\Invoices\ExportTestWorkspace;
use Tests\Support\Invoices\IsolatesSalesRegisterFiles;
use Tests\TestCase;
use ZipArchive;

class SalesRegisterExportIsolationTest extends TestCase
{
    use IsolatesSalesRegisterFiles;

    #[DataProvider('formats')]
    public function test_same_download_names_coexist_and_send_or_failure_only_removes_owned_file(string $format): void
    {
        $a = $this->download($format, 'A');
        $b = $this->download($format, 'B');
        $pathA = $a->getFile()->getPathname();
        $pathB = $b->getFile()->getPathname();
        $this->assertNotSame($pathA, $pathB);
        $this->assertSame($a->headers->get('Content-Disposition'), $b->headers->get('Content-Disposition'));
        $this->assertFileExists($pathA);
        $this->assertFileExists($pathB);
        $expectedA = file_get_contents($pathA);
        $expectedB = file_get_contents($pathB);
        $this->assertNotSame($expectedA, $expectedB);
        $this->assertSame($expectedA, $this->send($a));
        $this->assertFileDoesNotExist($pathA);
        $this->assertSame($expectedB, file_get_contents($pathB));

        $factory = app(ResponseFactory::class);
        $this->mock(ResponseFactory::class, fn ($mock) => $mock->shouldReceive('download')->once()->andThrow(new \RuntimeException('FAKE_FAILURE')));
        try {
            $this->download($format, 'failed');
            $this->fail('Expected controlled download failure.');
        } catch (InvoiceDomainException $exception) {
            $this->assertStringStartsWith('sales_register_', $exception->errorCode());
            $this->assertStringNotContainsString('FAKE_FAILURE', $exception->getMessage());
        } finally {
            $this->app->instance(ResponseFactory::class, $factory);
        }
        $files = array_map('realpath', $this->exportWorkspace->exportFiles());
        $this->assertSame([realpath($pathB)], $files);
        $this->assertSame($expectedB, $this->send($b));
        $this->assertFileDoesNotExist($pathB);
        $this->assertSame([], $this->exportWorkspace->exportFiles());
        Http::assertNothingSent();
    }

    public static function formats(): array
    {
        return [['xlsx'], ['xml'], ['jpk']];
    }

    public function test_workspace_restores_paths_and_keeps_neighbor_sentinel_after_exception(): void
    {
        $paths = fn () => [storage_path(), config('view.compiled'), config('filesystems.disks.local.root'),
            config('logging.channels.single.path'), app()->getCachedConfigPath(), app()->getCachedPackagesPath(),
            getenv('SALES_REGISTER_PREVIEW_DIR'), $_ENV['LARAVEL_STORAGE_PATH'], $_SERVER['LARAVEL_STORAGE_PATH']];
        $before = $paths();
        $neighbor = new ExportTestWorkspace;
        $nested = new ExportTestWorkspace;
        $sentinel = $neighbor->root.'/scratch/sentinel.txt';
        file_put_contents($sentinel, 'FAKE_NEIGHBOR_UNCHANGED');
        try {
            try {
                $nested->activate();
                $app = new Application(base_path());
                $this->assertSame($nested->root.'/storage', $app->storagePath());
                $this->assertNotSame($before[0], $app->storagePath());
                throw new \RuntimeException('FAKE_SCENARIO_FAILURE');
            } catch (\RuntimeException $exception) {
                $this->assertSame('FAKE_SCENARIO_FAILURE', $exception->getMessage());
            } finally {
                $this->assertSame([], $nested->exportFiles(), 'Check leaks before deleting the workspace.');
                $nested->restore();
                $nested->remove();
                Application::setInstance($this->app);
            }
            $this->assertSame($before, $paths());
            $next = new Application(base_path());
            $this->assertSame($before[0], $next->storagePath());
            $this->assertDirectoryExists($next->storagePath());
            $this->assertSame('FAKE_NEIGHBOR_UNCHANGED', file_get_contents($sentinel));
        } finally {
            $neighbor->remove();
            // Standalone Application instances replace the container singleton, not this test's bindings.
            Application::setInstance($this->app);
        }
    }

    public function test_controlled_glob_interleaving_reproduces_shared_directory_assertion_race(): void
    {
        $shared = new ExportTestWorkspace;
        $directory = $shared->root.'/storage/app/private/sales-register-exports';
        $process = proc_open([PHP_BINARY, base_path('tests/Support/Invoices/glob-interleaving.php'), $directory],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            $this->assertSame("READY\n", fgets($pipes[1]));
            $before = glob($directory.'/register-*');
            fwrite($pipes[0], "CREATE\n");
            $this->assertSame("CREATED\n", fgets($pipes[1]));
            $during = glob($directory.'/register-*');
            $this->assertNotSame($before, $during, 'An unrelated process invalidates the old whole-directory assertion.');
            $this->assertSame([], $this->exportWorkspace->exportFiles(), 'The isolated scenario sees none of its neighbor files.');
            fwrite($pipes[0], "DELETE\n");
            $this->assertSame("DELETED\n", fgets($pipes[1]));
            $this->assertSame($before, glob($directory.'/register-*'));
            $this->assertSame('', stream_get_contents($pipes[2]));
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $exit = proc_close($process);
            try {
                $this->assertSame(0, $exit);
                $this->assertSame([], $shared->exportFiles());
            } finally {
                $shared->remove();
            }
        }
    }

    private function download(string $format, string $label): BinaryFileResponse
    {
        $report = ['records' => [], 'selection' => [], 'warnings' => [
            ['document_id' => $label === 'A' ? 1 : 2, 'section' => 'selection', 'code' => 'document_missing'],
        ]];
        $filters = SalesRegisterFilters::forDocuments([1], false);
        if ($format === 'jpk') {
            $context = new JpkV7m3Context(['jpk_year' => '2026', 'jpk_month' => '9', 'jpk_type' => 'organization',
                'jpk_name' => 'Fictional '.$label, 'jpk_nip' => '1234563218', 'jpk_office' => '0202',
                'jpk_email' => 'fixture@example.test', 'jpk_purpose' => '1']);
            $exporter = app(JpkV7m3Exporter::class);
            $prepared = $exporter->prepare($report, $context, []);
            $this->assertSame([], $prepared['errors']);

            return $exporter->download($prepared, $context);
        }

        return app($format === 'xlsx' ? SalesRegisterXlsxExporter::class : SalesRegisterXmlExporter::class)->download($report, $filters);
    }

    private function send(BinaryFileResponse $response): string
    {
        $path = $response->getFile()->getPathname();
        if (str_ends_with($path, '.xlsx')) {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            try {
                $this->assertNotFalse($zip->getFromName('xl/workbook.xml'));
                $this->assertNotFalse($zip->getFromName('xl/worksheets/sheet2.xml'));
            } finally {
                $zip->close();
            }
        } elseif (str_contains(basename($path), 'jpk-')) {
            $this->assertSame([], app(JpkV7m3SchemaValidator::class)->errors(file_get_contents($path)));
        } else {
            $this->assertTrue((new DOMDocument)->load($path, LIBXML_NONET));
        }
        ob_start();
        try {
            $response->sendContent();

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
