<?php

namespace App\Enums;

enum QueueEntryStatus: string
{
    case Waiting = 'waiting';
    case Next = 'next';
    case Serving = 'serving';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
