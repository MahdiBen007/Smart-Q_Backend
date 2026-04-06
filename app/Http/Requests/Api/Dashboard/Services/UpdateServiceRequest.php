<?php

namespace App\Http\Requests\Api\Dashboard\Services;

use App\Models\Service;
use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends ServiceRequest
{
    public function rules(): array
    {
        /** @var Service $service */
        $service = $this->route('service');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'service_code' => ['sometimes', 'string', 'max:50', Rule::unique('services', 'service_code')->ignore($service->getKey())],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'icon' => ['sometimes', 'nullable', 'string', Rule::in(DashboardCatalog::SERVICE_ICONS)],
            'average_service_duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:180'],
            'status' => ['sometimes', Rule::in(DashboardCatalog::SERVICE_STATUSES)],
            'branch_ids' => ['sometimes', 'array', 'min:1'],
            'branch_ids.*' => ['required_with:branch_ids', $this->branchExistsRule()],
        ];
    }
}
