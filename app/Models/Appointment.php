<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'customer_id',
        'branch_id',
        'service_id',
        'staff_id',
        'appointment_date',
        'appointment_time',
        'appointment_end_time',
        'appointment_time_label',
        'appointment_session_id',
        'appointment_channel',
        'appointment_status',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date',
            'appointment_status' => AppointmentStatus::class,
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

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
    }

    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class);
    }

    public function qrCodeTokens(): HasMany
    {
        return $this->hasMany(QrCodeToken::class);
    }

    public function walkInTickets(): HasMany
    {
        return $this->hasMany(WalkInTicket::class);
    }
}
