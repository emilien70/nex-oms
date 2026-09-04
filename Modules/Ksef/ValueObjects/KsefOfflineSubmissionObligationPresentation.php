<?php

namespace Modules\Ksef\ValueObjects;

final readonly class KsefOfflineSubmissionObligationPresentation
{
    public function __construct(
        public string $label,
        public string $variant,
        public string $tooltip,
        public bool $urgent,
    ) {}
}
