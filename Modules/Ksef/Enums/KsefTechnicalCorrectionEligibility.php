<?php

namespace Modules\Ksef\Enums;

enum KsefTechnicalCorrectionEligibility: string
{
    case Eligible = 'eligible';
    case Ineligible = 'ineligible';
    case Unknown = 'unknown';
}
