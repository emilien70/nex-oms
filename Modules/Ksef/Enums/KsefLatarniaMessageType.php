<?php

namespace Modules\Ksef\Enums;

enum KsefLatarniaMessageType: string
{
    case MaintenanceAnnouncement = 'MAINTENANCE_ANNOUNCEMENT';
    case FailureStart = 'FAILURE_START';
    case FailureEnd = 'FAILURE_END';
}
