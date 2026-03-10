<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'notification_type',
        'title',
        'description',
        'tone',
        'action_path',
        'occurred_at',
        'notification_channel',
        'delivery_status',
        'message_content',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'notification_channel' => NotificationChannel::class,
            'delivery_status' => NotificationDeliveryStatus::class,
            'occurred_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
