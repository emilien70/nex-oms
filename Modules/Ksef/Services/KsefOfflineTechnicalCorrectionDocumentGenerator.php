<?php

namespace Modules\Ksef\Services;

use DateTimeInterface;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionDocumentGenerator;
use Modules\Ksef\Services\Fa3\KsefFa3DocumentGenerator;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3GeneratedDocument;

final class KsefOfflineTechnicalCorrectionDocumentGenerator
{
    public function __construct(
        private readonly KsefFa3DocumentGenerator $invoiceGenerator,
        private readonly KsefFa3CorrectionDocumentGenerator $correctionGenerator,
    ) {}

    public function generate(
        Invoice $document,
        DateTimeInterface $generatedAt,
    ): KsefFa3GeneratedDocument {
        if ($document->isInvoice()) {
            return $this->invoiceGenerator->generate(
                $document,
                $generatedAt,
                KsefFa3EligibilityMode::Authoritative,
            );
        }

        if ($document->isCorrection()) {
            return $this->correctionGenerator->generate(
                $document,
                $generatedAt,
                KsefFa3EligibilityMode::Authoritative,
            );
        }

        throw new KsefApiException(
            'Ten typ dokumentu nie jest obsługiwany przez generator korekty technicznej KSeF.',
            'ksef_technical_correction_document_type_not_supported',
        );
    }
}
