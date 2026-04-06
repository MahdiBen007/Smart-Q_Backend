<?php

namespace App\Http\Requests\Api\Dashboard\Staff;

use App\Http\Requests\Api\Dashboard\DashboardIndexRequest;
use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class ListStaffRequest extends DashboardIndexRequest
{
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branch_id' => ['sometimes', 'nullable', $this->branchExistsRule()],
            'role' => ['sometimes', Rule::in(DashboardCatalog::STAFF_ROLES)],
            'status' => ['sometimes', Rule::in(DashboardCatalog::STAFF_STATUSES)],
            'online' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            ...$this->paginationAttributes(),
            'search' => 'search term',
            'branch_id' => 'branch',
            'role' => 'staff role',
            'status' => 'staff status',
            'online' => 'online flag',
        ];
    }
}
