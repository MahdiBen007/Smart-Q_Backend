<?php

namespace App\Http\Requests\Api\Dashboard\WalkIns;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;
use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class CheckInWalkInRequest extends DashboardFormRequest
{
    public function rules(): array
    {
        return [
            'kiosk_id' => ['nullable', 'exists:kiosk_devices,id'],
            'result' => ['nullable', Rule::in(DashboardCatalog::CHECK_IN_RESULTS)],
        ];
    }

    public function attributes(): array
    {
        return [
            'kiosk_id' => 'kiosk',
            'result' => 'check-in result',
        ];
    }
}
