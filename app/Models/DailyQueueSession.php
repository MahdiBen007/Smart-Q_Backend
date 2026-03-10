<?php

namespace App\Models;

use App\Enums\QueueSessionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyQueueSession extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'branch_id',
        'service_id',
        'session_date',
        'session_start_time',
        'session_end_time',
        'session_status',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'session_status' => QueueSessionStatus::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class, 'queue_session_id');
    }

    public function walkInTickets(): HasMany
    {
        return $this->hasMany(WalkInTicket::class, 'queue_session_id');
    }
}
