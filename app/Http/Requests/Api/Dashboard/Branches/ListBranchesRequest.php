<?php

namespace App\Http\Requests\Api\Dashboard\Branches;

use App\Http\Requests\Api\Dashboard\DashboardIndexRequest;
use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class ListBranchesRequest extends DashboardIndexRequest
{
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(DashboardCatalog::BRANCH_STATUSES)],
        ];
    }

    public function attributes(): array
    {
        return [
            ...$this->paginationAttributes(),
            'search' => 'search term',
            'status' => 'branch status',
        ];
    }
}
