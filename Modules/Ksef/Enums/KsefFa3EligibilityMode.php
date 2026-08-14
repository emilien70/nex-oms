<?php

namespace Modules\Ksef\Enums;

enum KsefFa3EligibilityMode: string
{
    case Preflight = 'preflight';
    case Authoritative = 'authoritative';
}
