<?php

namespace Modules\Ksef\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefLatarniaEvidenceCoverage;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationReason;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationStatus;

final readonly class KsefOfflineSubmissionObligation
{
    /**
     * @param  list<int>  $appliedEventIds
     * @param  list<string>  $appliedMessageIds
     */
    public function __construct(
        public KsefOfflineSubmissionObligationStatus $status,
        public CarbonImmutable $baseDeadline,
        public ?CarbonImmutable $effectiveDeadline,
        public KsefOfflineSubmissionObligationReason $reason,
        public KsefLatarniaEvidenceCoverage $evidenceCoverage,
        public array $appliedEventIds,
        public array $appliedMessageIds,
        public ?KsefInvoiceSubmissionStatus $lastSubmissionStatus,
        public CarbonImmutable $evaluatedAt,
        public ?CarbonImmutable $ordinaryFailureEndDate = null,
        public ?int $totalFailureEventId = null,
    ) {}
}
