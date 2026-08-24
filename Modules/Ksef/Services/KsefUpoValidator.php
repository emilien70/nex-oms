<?php

namespace Modules\Ksef\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;

class KsefUpoValidator
{
    public const SCHEMA_ID = 'UPO v4-3';

    public const SCHEMA_VERSION = '4-3';

    public const XML_NAMESPACE = KsefUpoSchemaCompatibilityProjector::UPO_NAMESPACE;

    public const XMLDSIG_NAMESPACE = KsefUpoSchemaCompatibilityProjector::XMLDSIG_NAMESPACE;

    private const FORM_CODE = 'FA (3)';

    private const LOGICAL_STRUCTURE = 'Schemat_FA(3)_v1-0E.xsd';

    public function __construct(
        private readonly KsefUpoSchemaCompatibilityProjector $schemaCompatibility = new KsefUpoSchemaCompatibilityProjector,
    ) {}

    public function validate(
        string $xml,
        KsefInvoiceSubmission $submission,
        Invoice $invoice,
    ): void {
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
                throw $this->error(
                    'ksef_upo_xml_malformed',
                    'KSeF zwrócił nieprawidłowy dokument XML UPO.',
                );
            }

            $root = $document->documentElement;
            if (! $root instanceof DOMElement
                || $root->localName !== 'Potwierdzenie'
                || $root->namespaceURI !== self::XML_NAMESPACE) {
                throw $this->error(
                    'ksef_upo_schema_invalid',
                    'KSeF zwrócił dokument spoza obsługiwanego schematu UPO.',
                );
            }

            $version = $root->getAttribute('wersjaSchemy');
            if ($version !== '' && $version !== self::SCHEMA_VERSION) {
                throw $this->error(
                    'ksef_upo_schema_version_unsupported',
                    'KSeF zwrócił nieobsługiwaną wersję dokumentu UPO.',
                );
            }

            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('upo', self::XML_NAMESPACE);
            $xpath->registerNamespace('ds', self::XMLDSIG_NAMESPACE);
            $expectedReceiverName = $this->expectedReceiverName($submission->environment);
            $this->assertReceiverName($xpath, $expectedReceiverName);
            $this->assertSignature($xpath);
            $this->assertIdentity($xpath, $submission, $invoice);

            $projection = $this->schemaCompatibility->project($xml, $expectedReceiverName);
            if (! $projection->schemaValidate($this->schemaPath(), LIBXML_NONET)) {
                throw $this->error(
                    'ksef_upo_schema_invalid',
                    'Dokument UPO nie jest zgodny z oficjalnym schematem UPO v4-3.',
                );
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    public function schemaPath(): string
    {
        return $this->schemaDirectory().DIRECTORY_SEPARATOR.'upo-v4-3.xsd';
    }

    public function manifestPath(): string
    {
        return $this->schemaDirectory().DIRECTORY_SEPARATOR.'manifest.json';
    }

    private function schemaDirectory(): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'Schemas'
            .DIRECTORY_SEPARATOR.'UPO'.DIRECTORY_SEPARATOR.'4-3';
    }

    private function assertIdentity(
        DOMXPath $xpath,
        KsefInvoiceSubmission $submission,
        Invoice $invoice,
    ): void {
        if ($xpath->query('/upo:Potwierdzenie/upo:Dokument')->length !== 1) {
            throw $this->error(
                'ksef_upo_document_count_invalid',
                'Indywidualne UPO nie wskazuje dokładnie jednej Faktury.',
            );
        }

        $this->assertSame(
            $submission->session_reference_number,
            $this->value($xpath, '/upo:Potwierdzenie/upo:NumerReferencyjnySesji'),
            'ksef_upo_session_mismatch',
            'UPO nie odpowiada sesji wysyłki Faktury.',
        );
        $this->assertSame(
            $submission->context_nip,
            $this->value($xpath, '/upo:Potwierdzenie/upo:Uwierzytelnienie/upo:IdKontekstu/upo:Nip'),
            'ksef_upo_context_mismatch',
            'UPO nie odpowiada kontekstowi wysyłki Faktury.',
        );
        $this->assertSame(
            self::LOGICAL_STRUCTURE,
            $this->value($xpath, '/upo:Potwierdzenie/upo:NazwaStrukturyLogicznej'),
            'ksef_upo_structure_mismatch',
            'UPO wskazuje nieobsługiwaną strukturę Faktury.',
        );
        $this->assertSame(
            self::FORM_CODE,
            $this->value($xpath, '/upo:Potwierdzenie/upo:KodFormularza'),
            'ksef_upo_form_mismatch',
            'UPO wskazuje nieobsługiwany formularz Faktury.',
        );
        $this->assertSame(
            $submission->seller_nip,
            $this->value($xpath, '/upo:Potwierdzenie/upo:Dokument/upo:NipSprzedawcy'),
            'ksef_upo_seller_mismatch',
            'UPO nie odpowiada sprzedawcy wysłanej Faktury.',
        );
        $this->assertSame(
            $submission->ksef_number,
            $this->value($xpath, '/upo:Potwierdzenie/upo:Dokument/upo:NumerKSeFDokumentu'),
            'ksef_upo_ksef_number_mismatch',
            'UPO nie odpowiada numerowi KSeF wysłanej Faktury.',
        );
        $this->assertSame(
            $invoice->number,
            $this->value($xpath, '/upo:Potwierdzenie/upo:Dokument/upo:NumerFaktury'),
            'ksef_upo_invoice_number_mismatch',
            'UPO nie odpowiada numerowi wysłanej Faktury.',
        );
        $this->assertSame(
            $submission->invoice_hash,
            $this->value($xpath, '/upo:Potwierdzenie/upo:Dokument/upo:SkrotDokumentu'),
            'ksef_upo_invoice_hash_mismatch',
            'UPO nie odpowiada utrwalonemu XML-owi wysłanej Faktury.',
        );
        $this->assertSame(
            'Online',
            $this->value($xpath, '/upo:Potwierdzenie/upo:Dokument/upo:TrybWysylki'),
            'ksef_upo_delivery_mode_invalid',
            'UPO nie dotyczy obsługiwanego trybu wysyłki online.',
        );
    }

    private function assertReceiverName(DOMXPath $xpath, string $expected): void
    {
        $nodes = $xpath->query('/upo:Potwierdzenie/upo:NazwaPodmiotuPrzyjmujacego');
        $receiver = $nodes->length === 1 ? $nodes->item(0) : null;

        if (! $receiver instanceof DOMElement || ! hash_equals($expected, $receiver->textContent)) {
            throw $this->error(
                'ksef_upo_receiver_mismatch',
                'Dokument UPO nie odpowiada środowisku KSeF.',
            );
        }
    }

    private function assertSignature(DOMXPath $xpath): void
    {
        if ($xpath->query('//ds:Signature')->length !== 1
            || $xpath->query('/upo:Potwierdzenie/ds:Signature')->length !== 1) {
            throw $this->error(
                'ksef_upo_signature_invalid',
                'Dokument UPO nie zawiera dokładnie jednego podpisu XMLDSig.',
            );
        }
    }

    private function expectedReceiverName(KsefEnvironment $environment): string
    {
        return match ($environment) {
            KsefEnvironment::Test => 'Ministerstwo Finansów - środowisko testowe (TE)',
            KsefEnvironment::Demo => 'Ministerstwo Finansów - środowisko przedprodukcyjne (TR)',
            KsefEnvironment::Production => KsefUpoSchemaCompatibilityProjector::XSD_RECEIVER_NAME,
        };
    }

    private function value(DOMXPath $xpath, string $expression): string
    {
        return trim((string) $xpath->evaluate('string('.$expression.')'));
    }

    private function assertSame(
        mixed $expected,
        string $actual,
        string $safeCode,
        string $message,
    ): void {
        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $actual)) {
            throw $this->error($safeCode, $message);
        }
    }

    private function error(string $safeCode, string $message): KsefApiException
    {
        return new KsefApiException($message, $safeCode);
    }
}
