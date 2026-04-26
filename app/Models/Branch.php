<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'branch_name',
        'branch_address',
        'logo_url',
        'latitude',
        'longitude',
        'branch_code',
        'branch_status',
        'pin_top',
        'pin_left',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'pin_top' => 'integer',
            'pin_left' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)
            ->withPivot([
                'service_name_override',
                'service_subtitle_override',
                'service_description_override',
                'service_icon_override',
                'average_service_duration_minutes_override',
                'is_active_override',
            ])
            ->withTimestamps();
    }

    public function staffMembers(): HasMany
    {
        return $this->hasMany(StaffMember::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function walkInTickets(): HasMany
    {
        return $this->hasMany(WalkInTicket::class);
    }

    public function dailyQueueSessions(): HasMany
    {
        return $this->hasMany(DailyQueueSession::class);
    }

    public function kioskDevices(): HasMany
    {
        return $this->hasMany(KioskDevice::class);
    }
}
