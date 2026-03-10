<?php

namespace App\Http\Requests\Api\Dashboard\Services;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;

abstract class ServiceRequest extends DashboardFormRequest
{
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'branch_ids.min' => 'At least one branch must be assigned to the service.',
            'branch_ids.*.exists' => 'One of the selected service branches is invalid.',
        ]);
    }

    public function attributes(): array
    {
        return [
            'name' => 'service name',
            'service_code' => 'service code',
            'subtitle' => 'service subtitle',
            'description' => 'service description',
            'icon' => 'service icon',
            'average_service_duration_minutes' => 'average service duration',
            'status' => 'service status',
            'branch_ids' => 'service branches',
            'branch_ids.*' => 'service branch',
        ];
    }
}
