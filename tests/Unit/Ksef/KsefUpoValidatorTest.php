<?php

namespace Tests\Unit\Ksef;

use DOMDocument;
use DOMXPath;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefUpoSchemaCompatibilityProjector;
use Modules\Ksef\Services\KsefUpoValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\KsefUpoFixture;

class KsefUpoValidatorTest extends TestCase
{
    public function test_realistic_signed_test_upo_passes_compatibility_xsd_and_identity_validation(): void
    {
        [$invoice, $submission] = $this->identity();

        $this->validator()->validate(
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

    public function test_projection_preserves_original_and_only_projects_two_documented_conflicts(): void
    {
        [$invoice, $submission] = $this->identity();
        $xml = KsefUpoFixture::xml([
            'invoice_number' => $invoice->number,
            'invoice_hash' => $submission->invoice_hash,
            'ksef_number' => $submission->ksef_number,
        ]);
        $hashBefore = hash('sha256', $xml);
        $original = new DOMDocument;
        $this->assertTrue($original->loadXML($xml, LIBXML_NONET));
        $originalDomBefore = $original->saveXML();

        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $this->assertFalse($original->schemaValidate($this->validator()->schemaPath(), LIBXML_NONET));
            $projection = (new KsefUpoSchemaCompatibilityProjector)->project(
                $xml,
                'Ministerstwo Finansów - środowisko testowe (TE)',
            );
            $this->assertTrue($projection->schemaValidate($this->validator()->schemaPath(), LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $originalXpath = new DOMXPath($original);
        $originalXpath->registerNamespace('upo', KsefUpoValidator::XML_NAMESPACE);
        $originalXpath->registerNamespace('ds', KsefUpoValidator::XMLDSIG_NAMESPACE);
        $projectionXpath = new DOMXPath($projection);
        $projectionXpath->registerNamespace('upo', KsefUpoValidator::XML_NAMESPACE);
        $projectionXpath->registerNamespace('ds', KsefUpoValidator::XMLDSIG_NAMESPACE);

        $this->assertSame($hashBefore, hash('sha256', $xml));
        $this->assertSame($originalDomBefore, $original->saveXML());
        $this->assertSame(1, $originalXpath->query('//ds:Signature')->length);
        $this->assertSame(
            'Ministerstwo Finansów - środowisko testowe (TE)',
            $originalXpath->evaluate('string(/upo:Potwierdzenie/upo:NazwaPodmiotuPrzyjmujacego)'),
        );
        $this->assertSame(0, $projectionXpath->query('//ds:Signature')->length);
        $this->assertSame(
            KsefUpoSchemaCompatibilityProjector::XSD_RECEIVER_NAME,
            $projectionXpath->evaluate('string(/upo:Potwierdzenie/upo:NazwaPodmiotuPrzyjmujacego)'),
        );
    }

    #[DataProvider('receiverNameProvider')]
    public function test_receiver_name_must_exactly_match_environment(
        KsefEnvironment $environment,
        string $receiverName,
    ): void {
        [$invoice, $submission] = $this->identity();
        $submission->environment = $environment;

        $this->validator()->validate(KsefUpoFixture::xml([
            'receiver_name' => $receiverName,
            'invoice_number' => $invoice->number,
            'invoice_hash' => $submission->invoice_hash,
            'ksef_number' => $submission->ksef_number,
        ]), $submission, $invoice);

        $this->addToAssertionCount(1);
    }

    public static function receiverNameProvider(): array
    {
        return [
            'TEST' => [KsefEnvironment::Test, 'Ministerstwo Finansów - środowisko testowe (TE)'],
            'DEMO' => [KsefEnvironment::Demo, 'Ministerstwo Finansów - środowisko przedprodukcyjne (TR)'],
            'PRODUCTION' => [KsefEnvironment::Production, 'Ministerstwo Finansów'],
        ];
    }

    #[DataProvider('invalidTestReceiverNameProvider')]
    public function test_test_receiver_name_rejects_every_non_exact_value(string $receiverName): void
    {
        [$invoice, $submission] = $this->identity();

        try {
            $this->validator()->validate(KsefUpoFixture::xml([
                'receiver_name' => $receiverName,
                'invoice_number' => $invoice->number,
                'invoice_hash' => $submission->invoice_hash,
                'ksef_number' => $submission->ksef_number,
            ]), $submission, $invoice);
            $this->fail('Oczekiwano odrzucenia nieprawidłowej nazwy odbiorcy UPO.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_upo_receiver_mismatch', $exception->safeCode);
        }
    }

    public static function invalidTestReceiverNameProvider(): array
    {
        return [
            'production value' => ['Ministerstwo Finansów'],
            'demo value' => ['Ministerstwo Finansów - środowisko przedprodukcyjne (TR)'],
            'random suffix' => ['Ministerstwo Finansów - środowisko testowe (TE) extra'],
            'leading whitespace' => [' Ministerstwo Finansów - środowisko testowe (TE)'],
            'trailing whitespace' => ['Ministerstwo Finansów - środowisko testowe (TE) '],
        ];
    }

    #[DataProvider('invalidSignatureProvider')]
    public function test_exactly_one_root_xmldsig_signature_is_required(string $signatureMode): void
    {
        [$invoice, $submission] = $this->identity();

        try {
            $this->validator()->validate(KsefUpoFixture::xml([
                'signature_mode' => $signatureMode,
                'invoice_number' => $invoice->number,
                'invoice_hash' => $submission->invoice_hash,
                'ksef_number' => $submission->ksef_number,
            ]), $submission, $invoice);
            $this->fail('Oczekiwano odrzucenia nieprawidłowej obecności podpisu UPO.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_upo_signature_invalid', $exception->safeCode);
        }
    }

    public static function invalidSignatureProvider(): array
    {
        return [
            'missing' => ['none'],
            'duplicate' => ['duplicate'],
            'UPO namespace' => ['upo_namespace'],
            'no namespace' => ['no_namespace'],
        ];
    }

    #[DataProvider('invalidXmlProvider')]
    public function test_invalid_xml_is_rejected_without_exposing_payload(
        string $xml,
        string $safeCode,
    ): void {
        [$invoice, $submission] = $this->identity();

        try {
            $this->validator()->validate($xml, $submission, $invoice);
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
            ]), 'ksef_upo_delivery_mode_invalid'],
            'multiple documents' => [KsefUpoFixture::xml([
                'duplicate_document' => true,
            ]), 'ksef_upo_document_count_invalid'],
            'doctype' => [str_replace(
                "?>\n",
                "?>\n<!DOCTYPE Potwierdzenie [<!ENTITY secret 'SECRET_UPO_CONTENT'>]>\n",
                KsefUpoFixture::xml(),
            ), 'ksef_upo_xml_malformed'],
            'other xsd failure after projection' => [KsefUpoFixture::xml([
                'issue_date' => 'NOT-A-DATE',
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

    private function validator(): KsefUpoValidator
    {
        return new KsefUpoValidator(new KsefUpoSchemaCompatibilityProjector);
    }
}
