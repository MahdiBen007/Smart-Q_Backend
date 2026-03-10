<?php

namespace App\Models;

use App\Enums\QueueEntryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueEntry extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'queue_session_id',
        'customer_id',
        'queue_position',
        'queue_status',
        'checked_in_at',
        'service_started_at',
        'served_by_staff_id',
        'appointment_id',
        'ticket_id',
    ];

    protected function casts(): array
    {
        return [
            'queue_position' => 'integer',
            'queue_status' => QueueEntryStatus::class,
            'checked_in_at' => 'datetime',
            'service_started_at' => 'datetime',
        ];
    }

    public function queueSession(): BelongsTo
    {
        return $this->belongsTo(DailyQueueSession::class, 'queue_session_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function servedByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'served_by_staff_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function walkInTicket(): BelongsTo
    {
        return $this->belongsTo(WalkInTicket::class, 'ticket_id');
    }
}
