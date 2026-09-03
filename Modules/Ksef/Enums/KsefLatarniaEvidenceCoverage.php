<?php

namespace Modules\Ksef\Enums;

enum KsefLatarniaEvidenceCoverage: string
{
    case Complete = 'complete';
    case Insufficient = 'insufficient';
    case UnsupportedEnvironment = 'unsupported_environment';
}
