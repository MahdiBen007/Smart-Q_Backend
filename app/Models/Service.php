<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'branch_id',
        'service_name',
        'average_service_duration_minutes',
        'is_active',
        'service_code',
        'service_subtitle',
        'service_description',
        'service_icon',
    ];

    protected function casts(): array
    {
        return [
            'average_service_duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)
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

    public function counters(): BelongsToMany
    {
        return $this->belongsToMany(Counter::class)
            ->withTimestamps();
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
}
