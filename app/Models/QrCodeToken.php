<?php

namespace App\Models;

use App\Enums\TokenStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QrCodeToken extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'token_value',
        'expiration_date_time',
        'used_date_time',
        'token_status',
        'appointment_id',
        'ticket_id',
    ];

    protected function casts(): array
    {
        return [
            'expiration_date_time' => 'datetime',
            'used_date_time' => 'datetime',
            'token_status' => TokenStatus::class,
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function walkInTicket(): BelongsTo
    {
        return $this->belongsTo(WalkInTicket::class, 'ticket_id');
    }

    public function checkInRecords(): HasMany
    {
        return $this->hasMany(CheckInRecord::class, 'qr_token_id');
    }
}
