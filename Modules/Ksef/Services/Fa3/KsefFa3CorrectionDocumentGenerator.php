<?php

namespace Modules\Ksef\Services\Fa3;

use DateTimeInterface;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Services\KsefFa3CorrectionEligibilityValidator;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3GeneratedDocument;

final class KsefFa3CorrectionDocumentGenerator
{
    public function __construct(
        private readonly KsefFa3CorrectionEligibilityValidator $eligibility,
        private readonly KsefSettingsService $settings,
        private readonly KsefFa3CorrectionSourceReferenceResolver $sourceReferences,
        private readonly KsefFa3CorrectionDocumentMapper $mapper,
        private readonly KsefFa3CorrectionXmlBuilder $builder,
        private readonly KsefFa3SchemaValidator $schemaValidator,
    ) {}

    public function generate(
        Invoice $correction,
        DateTimeInterface $generatedAt,
        KsefFa3EligibilityMode $mode,
    ): KsefFa3GeneratedDocument {
        $settings = $this->settings->getExisting();
        $this->eligibility->assertEligible($correction, $settings, $mode);
        $sourceReference = $this->sourceReferences->resolve($correction, $settings->environment);
        $data = $this->mapper->map($correction, $sourceReference, $generatedAt);
        $xml = $this->builder->build($data);
        $this->schemaValidator->validate($xml);

        return new KsefFa3GeneratedDocument(
            xml: $xml,
            generatedAt: $data->generatedAt,
            schemaId: KsefFa3SchemaValidator::SCHEMA_ID,
        );
    }
}
