<?php

namespace App\Http\Requests\Api\Mobile\Bookings;

use App\Http\Requests\Api\Mobile\MobileFormRequest;

class CancelBookingRequest extends MobileFormRequest
{
    public function rules(): array
    {
        return [
            'appointment_id' => ['required', 'uuid', 'exists:appointments,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'appointment_id' => 'appointment',
        ];
    }
}
