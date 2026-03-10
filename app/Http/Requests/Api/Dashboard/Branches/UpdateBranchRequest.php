<?php

namespace App\Http\Requests\Api\Dashboard\Branches;

use App\Models\Branch;
use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends BranchRequest
{
    public function rules(): array
    {
        /** @var Branch $branch */
        $branch = $this->route('branch');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('branches', 'branch_code')->ignore($branch->getKey())],
            'address' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(DashboardCatalog::BRANCH_STATUSES)],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'pin_top' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'pin_left' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
        ];
    }
}
