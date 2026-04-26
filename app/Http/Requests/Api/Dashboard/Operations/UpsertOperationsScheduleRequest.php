<?php

namespace App\Http\Requests\Api\Dashboard\Operations;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;
use Illuminate\Validation\Rule;

class UpsertOperationsScheduleRequest extends DashboardFormRequest
{
    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', Rule::in(['branch', 'service', 'global'])],
            'branch_id' => ['nullable', 'string', $this->branchExistsRule()],
            'service_id' => ['nullable', 'string', $this->serviceExistsRule()],
            'status' => ['nullable', 'string', Rule::in(['draft', 'published'])],
            'schedule' => ['required', 'array'],
        ];
    }
}
