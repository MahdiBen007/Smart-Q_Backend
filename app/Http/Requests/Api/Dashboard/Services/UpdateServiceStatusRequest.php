<?php

namespace App\Http\Requests\Api\Dashboard\Services;

use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class UpdateServiceStatusRequest extends ServiceRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(DashboardCatalog::SERVICE_STATUSES)],
        ];
    }
}
