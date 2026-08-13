<?php

namespace Modules\Ksef\Enums;

enum KsefConnectionTestStatus: string
{
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
}
