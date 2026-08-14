<?php

namespace Modules\Ksef\Services\Fa3;

use DateTimeInterface;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Services\KsefFa3EligibilityValidator;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3GeneratedDocument;

class KsefFa3DocumentGenerator
{
    public function __construct(
        private readonly KsefFa3EligibilityValidator $eligibility,
        private readonly KsefSettingsService $settings,
        private readonly KsefFa3InvoiceMapper $mapper,
        private readonly KsefFa3XmlBuilder $builder,
        private readonly KsefFa3SchemaValidator $schemaValidator,
    ) {}

    public function generate(
        Invoice $invoice,
        DateTimeInterface $generatedAt,
        KsefFa3EligibilityMode $mode,
    ): KsefFa3GeneratedDocument {
        $this->eligibility->assertEligible($invoice, $this->settings->getExisting(), $mode);
        $data = $this->mapper->map($invoice, $generatedAt);
        $xml = $this->builder->build($data);
        $this->schemaValidator->validate($xml);

        return new KsefFa3GeneratedDocument(
            xml: $xml,
            generatedAt: $data->generatedAt,
            schemaId: KsefFa3SchemaValidator::SCHEMA_ID,
        );
    }
}
