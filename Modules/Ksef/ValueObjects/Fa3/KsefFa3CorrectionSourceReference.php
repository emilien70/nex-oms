<?php

namespace Modules\Ksef\ValueObjects\Fa3;

use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefFa3CorrectionRootReferenceType;

final readonly class KsefFa3CorrectionSourceReference
{
    /**
     * @param  list<array{correction_id: int, submission_id: int, ksef_number: string}>  $precedingCorrections
     */
    private function __construct(
        public KsefEnvironment $environment,
        public int $rootInvoiceId,
        public string $rootInvoiceNumber,
        public string $correctedInvoiceIssueDate,
        public KsefFa3CorrectionRootReferenceType $rootReferenceType,
        public ?int $rootSubmissionId,
        public ?string $rootKsefNumber,
        public ?int $rootProvenanceId,
        public array $precedingCorrections,
    ) {}

    /**
     * @param  list<array{correction_id: int, submission_id: int, ksef_number: string}>  $precedingCorrections
     */
    public static function ksef(
        KsefEnvironment $environment,
        int $rootInvoiceId,
        string $rootInvoiceNumber,
        string $correctedInvoiceIssueDate,
        int $rootSubmissionId,
        string $rootKsefNumber,
        array $precedingCorrections,
    ): self {
        return new self(
            environment: $environment,
            rootInvoiceId: $rootInvoiceId,
            rootInvoiceNumber: $rootInvoiceNumber,
            correctedInvoiceIssueDate: $correctedInvoiceIssueDate,
            rootReferenceType: KsefFa3CorrectionRootReferenceType::Ksef,
            rootSubmissionId: $rootSubmissionId,
            rootKsefNumber: $rootKsefNumber,
            rootProvenanceId: null,
            precedingCorrections: $precedingCorrections,
        );
    }

    /**
     * @param  list<array{correction_id: int, submission_id: int, ksef_number: string}>  $precedingCorrections
     */
    public static function outsideKsef(
        KsefEnvironment $environment,
        int $rootInvoiceId,
        string $rootInvoiceNumber,
        string $correctedInvoiceIssueDate,
        int $rootProvenanceId,
        array $precedingCorrections,
    ): self {
        return new self(
            environment: $environment,
            rootInvoiceId: $rootInvoiceId,
            rootInvoiceNumber: $rootInvoiceNumber,
            correctedInvoiceIssueDate: $correctedInvoiceIssueDate,
            rootReferenceType: KsefFa3CorrectionRootReferenceType::OutsideKsef,
            rootSubmissionId: null,
            rootKsefNumber: null,
            rootProvenanceId: $rootProvenanceId,
            precedingCorrections: $precedingCorrections,
        );
    }
}
