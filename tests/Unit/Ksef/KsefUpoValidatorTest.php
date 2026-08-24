<?php

namespace Tests\Unit\Ksef;

use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefUpoValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\KsefUpoFixture;

class KsefUpoValidatorTest extends TestCase
{
    public function test_valid_individual_upo_v4_3_passes_xsd_and_identity_validation(): void
    {
        [$invoice, $submission] = $this->identity();

        (new KsefUpoValidator)->validate(
            KsefUpoFixture::xml([
                'invoice_number' => $invoice->number,
                'invoice_hash' => $submission->invoice_hash,
                'ksef_number' => $submission->ksef_number,
            ]),
            $submission,
            $invoice,
        );

        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidXmlProvider')]
    public function test_invalid_xml_is_rejected_without_exposing_payload(
        string $xml,
        string $safeCode,
    ): void {
        [$invoice, $submission] = $this->identity();

        try {
            (new KsefUpoValidator)->validate($xml, $submission, $invoice);
            $this->fail('Oczekiwano odrzucenia nieprawidłowego UPO.');
        } catch (KsefApiException $exception) {
            $this->assertSame($safeCode, $exception->safeCode);
            $this->assertStringNotContainsString('SECRET_UPO_CONTENT', $exception->getMessage());
        }
    }

    public static function invalidXmlProvider(): array
    {
        return [
            'malformed' => ['<Potwierdzenie>SECRET_UPO_CONTENT', 'ksef_upo_xml_malformed'],
            'wrong namespace' => [KsefUpoFixture::xml([
                'namespace' => 'https://invalid.example/upo',
            ]), 'ksef_upo_schema_invalid'],
            'unknown schema version' => [KsefUpoFixture::xml([
                'version' => '9-9',
            ]), 'ksef_upo_schema_version_unsupported'],
            'xsd invalid mode' => [KsefUpoFixture::xml([
                'mode' => 'Batch',
            ]), 'ksef_upo_schema_invalid'],
            'multiple documents' => [KsefUpoFixture::xml([
                'duplicate_document' => true,
            ]), 'ksef_upo_schema_invalid'],
        ];
    }

    public function test_vendored_official_schema_matches_audited_manifest_hash(): void
    {
        $validator = new KsefUpoValidator;
        $manifest = json_decode(
            (string) file_get_contents($validator->manifestPath()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $path = dirname($validator->manifestPath()).DIRECTORY_SEPARATOR.'upo-v4-3.xsd';

        $this->assertSame(KsefUpoValidator::SCHEMA_ID, $manifest['schema']);
        $this->assertSame(KsefUpoValidator::XML_NAMESPACE, $manifest['namespace']);
        $this->assertSame('1c34fe2799387d517b83a2fb21e31e83d5f66247', $manifest['source_commit']);
        $this->assertFileExists($path);
        $this->assertSame($manifest['files']['upo-v4-3.xsd']['sha256'], hash_file('sha256', $path));
    }

    /** @return array{Invoice, KsefInvoiceSubmission} */
    private function identity(): array
    {
        $payload = '<Faktura>TEST</Faktura>';
        $invoice = new Invoice([
            'document_type' => 'invoice',
            'number' => 'FV 1/2026',
        ]);
        $submission = new KsefInvoiceSubmission([
            'environment' => KsefEnvironment::Test,
            'status' => KsefInvoiceSubmissionStatus::Accepted,
            'context_nip' => KsefUpoFixture::CONTEXT_NIP,
            'seller_nip' => KsefUpoFixture::SELLER_NIP,
            'schema_id' => 'FA (3) 1-0E',
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)),
            'session_reference_number' => KsefUpoFixture::SESSION_REFERENCE,
            'invoice_reference_number' => KsefUpoFixture::INVOICE_REFERENCE,
            'ksef_number' => KsefUpoFixture::ksefNumber(),
        ]);

        return [$invoice, $submission];
    }
}
