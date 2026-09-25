<?php

namespace App\Enums;

enum EmailAccountTestStatus: string
{
    case Success = 'success';
    case Error = 'error';
}
