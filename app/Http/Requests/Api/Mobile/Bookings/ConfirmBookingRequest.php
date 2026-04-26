<?php

namespace App\Http\Requests\Api\Mobile\Bookings;

use App\Http\Requests\Api\Mobile\MobileFormRequest;

class ConfirmBookingRequest extends MobileFormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'service_id' => ['required', 'uuid', 'exists:services,id'],
            'appointment_date' => ['required', 'date'],
            'appointment_time' => ['required', 'date_format:H:i'],
            'full_name' => ['nullable', 'string', 'min:2', 'max:120'],
        ];
    }

    public function attributes(): array
    {
        return [
            'branch_id' => 'branch',
            'service_id' => 'service',
            'appointment_date' => 'appointment date',
            'appointment_time' => 'appointment time',
            'full_name' => 'full name',
        ];
    }
}
