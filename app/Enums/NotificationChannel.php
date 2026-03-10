<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';
}
