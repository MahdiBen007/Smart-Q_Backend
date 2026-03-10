<?php

namespace App\Enums;

enum TicketSource: string
{
    case Reception = 'reception';
    case Kiosk = 'kiosk';
    case QrScan = 'qr_scan';
    case StaffAssisted = 'staff_assisted';
}
