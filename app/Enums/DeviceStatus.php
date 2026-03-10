<?php

namespace App\Enums;

enum DeviceStatus: string
{
    case Online = 'online';
    case Busy = 'busy';
    case Maintenance = 'maintenance';
}
