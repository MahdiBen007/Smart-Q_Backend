<?php

namespace App\Enums;

enum QueueSessionStatus: string
{
    case Live = 'live';
    case ClosingSoon = 'closing_soon';
    case Paused = 'paused';
}
