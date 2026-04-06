<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'full_name',
        'phone_number',
        'email_address',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function latestAppointment(): HasOne
    {
        return $this->hasOne(Appointment::class)->latestOfMany('created_at');
    }

    public function walkInTickets(): HasMany
    {
        return $this->hasMany(WalkInTicket::class);
    }

    public function latestWalkInTicket(): HasOne
    {
        return $this->hasOne(WalkInTicket::class)->latestOfMany('created_at');
    }

    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class);
    }

    public function latestQueueEntry(): HasOne
    {
        return $this->hasOne(QueueEntry::class)->ofMany('updated_at', 'max');
    }

    public function checkInRecords(): HasMany
    {
        return $this->hasMany(CheckInRecord::class);
    }
}
