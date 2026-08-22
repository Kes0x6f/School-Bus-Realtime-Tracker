<?php

namespace App\Enums;

enum ShiftEndReason: string
{
    case MANUAL = 'manual';
    case LOGOUT = 'logout';
    case AUTO = 'auto';
    case ACCOUNT_DEACTIVATED = 'account_deactivated';
}
