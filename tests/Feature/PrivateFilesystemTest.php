<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use Mockery;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Invoices\Services\InvoicePdfStorage;
use Tests\TestCase;

class PrivateFilesystemTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->root = sys_get_temp_dir().'/nex-oms-private-storage-test-'.Str::uuid();
    }

    protected function tearDown(): void
    {
        try {
            if (is_dir($this->root)) {
                $resolved = realpath($this->root);
                $this->assertNotFalse($resolved);
                $this->assertSame(realpath(sys_get_temp_dir()), dirname($resolved));
                $this->assertStringStartsWith('nex-oms-private-storage-test-', basename($resolved));
                (new Filesystem)->deleteDirectory($resolved);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_private_disk_explicitly_shares_files_and_directories_without_other_access(): void
    {
        $config = config('filesystems.disks.local');

        $this->assertSame('private', $config['visibility'] ?? null);
        $this->assertSame('private', $config['directory_visibility'] ?? null);

        $permissions = PortableVisibilityConverter::fromArray(
            $config['permissions'] ?? [],
            $config['directory_visibility'] ?? 'private',
        );

        $this->assertSame(0660, $permissions->forFile('private'));
        $this->assertSame(0770, $permissions->defaultForDirectories());
        $this->assertSame(0, $permissions->forFile('private') & 0007);
        $this->assertSame(0, $permissions->defaultForDirectories() & 0007);
        $this->assertSame('public', config('filesystems.disks.public.visibility'));
    }

    public function test_nested_invoice_and_attachment_files_are_stored_privately(): void
    {
        $disk = $this->disk();
        $paths = [
            'invoices/19/invoice-test.pdf',
            'order-email-messages/21/attachments/test.pdf',
        ];

        foreach ($paths as $path) {
            $this->assertTrue($disk->put($path, '%PDF-1.7 private storage test'));
            $this->assertSame('%PDF-1.7 private storage test', $disk->get($path));
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertSame('private', $disk->getVisibility($path));
            }
        }
    }

    public function test_invoice_cache_does_not_reset_existing_directory_permissions(): void
    {
        $disk = $this->disk();
        $proxy = Mockery::mock($disk);
        $proxy->shouldNotReceive('makeDirectory');
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

        $invoice = new Invoice;
        $path = 'invoices/19/invoice-test.pdf';
        $filenames = Mockery::mock(InvoicePdfFilenameGenerator::class);
        $filenames->shouldReceive('storagePath')->with($invoice)->andReturn($path);
        $storage = new InvoicePdfStorage($filenames);
        $contents = '%PDF-1.7 immutable cache test';

        $this->assertSame($contents, $storage->getOrCreate($invoice, fn (): string => $contents));
        $this->assertSame([$path], $disk->allFiles('invoices/19'));
        $this->assertSame($contents, $storage->getOrCreate($invoice, function (): never {
            $this->fail('An existing cached PDF must not be regenerated.');
        }));
    }

    public function test_private_filesystem_uses_group_accessible_posix_modes(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions require a native Unix filesystem.');
        }

        $previousUmask = umask(0007);

        try {
            $disk = $this->disk();

            foreach (['invoices/19/test.pdf', 'order-email-messages/21/attachments/test.pdf'] as $path) {
                $this->assertTrue($disk->put($path, '%PDF-1.7 POSIX mode test'));
                clearstatcache();
                $this->assertSame(0660, fileperms($disk->path($path)) & 0777);

                for ($directory = dirname($path); $directory !== '.'; $directory = dirname($directory)) {
                    $this->assertSame(0770, fileperms($disk->path($directory)) & 0777);
                }
            }
        } finally {
            umask($previousUmask);
        }
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::build([
            ...config('filesystems.disks.local'),
            'root' => $this->root,
        ]);
    }
}
