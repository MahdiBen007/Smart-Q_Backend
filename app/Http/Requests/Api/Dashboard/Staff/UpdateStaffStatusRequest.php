<?php

namespace App\Http\Requests\Api\Dashboard\Staff;

use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class UpdateStaffStatusRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(DashboardCatalog::STAFF_STATUSES)],
        ];
    }
}
