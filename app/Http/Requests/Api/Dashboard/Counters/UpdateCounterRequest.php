<?php

namespace App\Http\Requests\Api\Dashboard\Counters;

use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class UpdateCounterRequest extends CounterRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', $this->branchExistsRule()],
            'counter_code' => ['sometimes', 'string', 'max:50'],
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(DashboardCatalog::SERVICE_STATUSES)],
            'display_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
