<?php

namespace Modules\Ksef\ValueObjects\Fa3;

use Modules\Ksef\Enums\KsefEnvironment;

final readonly class KsefFa3CorrectionSourceReference
{
    /**
     * @param  list<array{correction_id: int, submission_id: int, ksef_number: string}>  $precedingCorrections
     */
    public function __construct(
        public KsefEnvironment $environment,
        public int $rootInvoiceId,
        public string $rootInvoiceNumber,
        public string $correctedInvoiceIssueDate,
        public int $rootSubmissionId,
        public string $rootKsefNumber,
        public array $precedingCorrections,
    ) {}
}
