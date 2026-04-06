<?php

namespace App\Http\Requests\Api\Dashboard\Customers;

use App\Http\Requests\Api\Dashboard\DashboardIndexRequest;

class ListCustomersRequest extends DashboardIndexRequest
{
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branch_id' => ['sometimes', 'nullable', $this->branchExistsRule()],
        ];
    }

    public function attributes(): array
    {
        return [
            ...$this->paginationAttributes(),
            'search' => 'search term',
            'branch_id' => 'branch',
        ];
    }
}
