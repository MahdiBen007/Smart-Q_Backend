<?php

namespace App\Models;

use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WalkInTicket extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'customer_id',
        'branch_id',
        'service_id',
        'queue_session_id',
        'appointment_id',
        'ticket_number',
        'ticket_source',
        'ticket_status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'ticket_number' => 'integer',
            'ticket_source' => TicketSource::class,
            'ticket_status' => TicketStatus::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function queueSession(): BelongsTo
    {
        return $this->belongsTo(DailyQueueSession::class, 'queue_session_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class, 'ticket_id');
    }

    public function qrCodeTokens(): HasMany
    {
        return $this->hasMany(QrCodeToken::class, 'ticket_id');
    }
}
