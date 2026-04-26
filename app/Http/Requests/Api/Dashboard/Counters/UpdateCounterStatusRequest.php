<?php

namespace App\Http\Requests\Api\Dashboard\Counters;

use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class UpdateCounterStatusRequest extends CounterRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(DashboardCatalog::SERVICE_STATUSES)],
        ];
    }
}
