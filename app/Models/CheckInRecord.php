<?php

namespace App\Models;

use App\Enums\CheckInResult;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckInRecord extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'qr_token_id',
        'kiosk_id',
        'customer_id',
        'check_in_date_time',
        'check_in_result',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date_time' => 'datetime',
            'check_in_result' => CheckInResult::class,
        ];
    }

    public function qrToken(): BelongsTo
    {
        return $this->belongsTo(QrCodeToken::class, 'qr_token_id');
    }

    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(KioskDevice::class, 'kiosk_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
