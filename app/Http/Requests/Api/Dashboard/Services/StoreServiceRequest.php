<?php

namespace App\Http\Requests\Api\Dashboard\Services;

use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends ServiceRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'service_code' => ['required', 'string', 'max:50', 'unique:services,service_code'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', Rule::in(DashboardCatalog::SERVICE_ICONS)],
            'average_service_duration_minutes' => ['required', 'integer', 'min:1', 'max:180'],
            'status' => ['required', Rule::in(DashboardCatalog::SERVICE_STATUSES)],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['required', 'exists:branches,id'],
        ];
    }
}
