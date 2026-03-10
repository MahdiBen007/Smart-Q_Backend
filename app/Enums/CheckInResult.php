<?php

namespace App\Enums;

enum CheckInResult: string
{
    case Success = 'success';
    case Pending = 'pending';
    case ManualAssist = 'manual_assist';
}
