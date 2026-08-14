<?php

namespace Tests\Unit\Ksef;

use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Ksef\Services\Fa3\KsefFa3SchemaValidator;
use PHPUnit\Framework\TestCase;

class KsefFa3SchemaValidatorTest extends TestCase
{
    public function test_it_rejects_invalid_xml_without_exposing_document_content_in_metadata(): void
    {
        $validator = new KsefFa3SchemaValidator;

        try {
            $validator->validate('<?xml version="1.0" encoding="UTF-8"?><Faktura>SECRET PII</Faktura>');
            $this->fail('Oczekiwano odrzucenia XML niezgodnego z FA(3).');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('ksef_fa3_schema_validation_failed', $exception->errorCode());
            $this->assertSame('FA (3) 1-0E', $exception->metadata()['schema']);
            $this->assertNotEmpty($exception->metadata()['errors']);
            $this->assertStringNotContainsString('SECRET PII', serialize($exception->metadata()));
        }
    }

    public function test_vendored_schema_files_match_the_audited_manifest_hashes(): void
    {
        $validator = new KsefFa3SchemaValidator;
        $manifest = json_decode((string) file_get_contents($validator->manifestPath()), true, flags: JSON_THROW_ON_ERROR);
        $directory = dirname($validator->manifestPath());

        $this->assertSame('FA (3) 1-0E', $manifest['schema']);
        $this->assertSame('1c34fe2799387d517b83a2fb21e31e83d5f66247', $manifest['source_commit']);
        foreach ($manifest['files'] as $file => $metadata) {
            $path = $directory.DIRECTORY_SEPARATOR.$file;
            $this->assertFileExists($path);
            $this->assertSame($metadata['sha256'], hash_file('sha256', $path));
        }
    }
}
