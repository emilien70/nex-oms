<?php

namespace Modules\Ksef\Enums;

enum KsefLatarniaMessageCategory: string
{
    case Maintenance = 'MAINTENANCE';
    case Failure = 'FAILURE';
    case TotalFailure = 'TOTAL_FAILURE';
}
