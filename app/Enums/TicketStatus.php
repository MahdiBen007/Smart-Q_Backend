<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Queued = 'queued';
    case CheckedIn = 'checked_in';
    case Serving = 'serving';
    case Completed = 'completed';
    case Escalated = 'escalated';
}
