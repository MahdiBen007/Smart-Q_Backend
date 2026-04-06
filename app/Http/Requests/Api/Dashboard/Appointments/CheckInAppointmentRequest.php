<?php

namespace App\Http\Requests\Api\Dashboard\Appointments;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;
use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class CheckInAppointmentRequest extends DashboardFormRequest
{
    public function rules(): array
    {
        return [
            'token_value' => ['required', 'string', 'max:255'],
            'kiosk_id' => ['nullable', $this->kioskExistsRule()],
            'result' => ['nullable', Rule::in(DashboardCatalog::CHECK_IN_RESULTS)],
        ];
    }

    public function attributes(): array
    {
        return [
            'token_value' => 'QR token',
            'kiosk_id' => 'kiosk',
            'result' => 'check-in result',
        ];
    }
}
