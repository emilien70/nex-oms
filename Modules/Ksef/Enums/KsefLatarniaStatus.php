<?php

namespace Modules\Ksef\Enums;

enum KsefLatarniaStatus: string
{
    case Available = 'AVAILABLE';
    case Maintenance = 'MAINTENANCE';
    case Failure = 'FAILURE';
    case TotalFailure = 'TOTAL_FAILURE';
}
