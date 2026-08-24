<?php

namespace Modules\Ksef\ValueObjects;

use Modules\Ksef\Enums\KsefEnvironment;

final readonly class KsefMonthlyInvoiceExportResult
{
    public function __construct(
        public KsefEnvironment $environment,
        public string $month,
        public int $eligibleCount,
        public int $submittedCount,
        public int $failedCount,
        public bool $stoppedEarly,
        public ?string $safeFailureSummary = null,
    ) {}
}
