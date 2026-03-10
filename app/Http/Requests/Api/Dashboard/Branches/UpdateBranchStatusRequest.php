<?php

namespace App\Http\Requests\Api\Dashboard\Branches;

use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class UpdateBranchStatusRequest extends BranchRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(DashboardCatalog::BRANCH_STATUSES)],
        ];
    }
}
