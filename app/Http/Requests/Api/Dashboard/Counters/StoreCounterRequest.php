<?php

namespace App\Http\Requests\Api\Dashboard\Counters;

use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class StoreCounterRequest extends CounterRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', $this->branchExistsRule()],
            'counter_code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(DashboardCatalog::SERVICE_STATUSES)],
            'display_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
