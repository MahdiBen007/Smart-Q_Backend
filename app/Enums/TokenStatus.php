<?php

namespace App\Enums;

enum TokenStatus: string
{
    case Active = 'active';
    case Consumed = 'consumed';
    case Expired = 'expired';
}
