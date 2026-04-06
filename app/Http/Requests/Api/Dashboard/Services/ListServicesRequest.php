<?php

namespace App\Http\Requests\Api\Dashboard\Services;

use App\Http\Requests\Api\Dashboard\DashboardIndexRequest;
use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class ListServicesRequest extends DashboardIndexRequest
{
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branch_id' => ['sometimes', 'nullable', $this->branchExistsRule()],
            'status' => ['sometimes', Rule::in(DashboardCatalog::SERVICE_STATUSES)],
        ];
    }

    public function attributes(): array
    {
        return [
            ...$this->paginationAttributes(),
            'search' => 'search term',
            'branch_id' => 'branch',
            'status' => 'service status',
        ];
    }
}
