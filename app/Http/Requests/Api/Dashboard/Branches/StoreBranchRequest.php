<?php

namespace App\Http\Requests\Api\Dashboard\Branches;

use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends BranchRequest
{
    public function rules(): array
    {
        $currentCompanyId = $this->currentCompanyId();

        return [
            'company_id' => $currentCompanyId !== null
                ? ['nullable', Rule::in([$currentCompanyId])]
                : ['nullable', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:branches,branch_code'],
            'address' => ['required', 'string', 'max:255'],
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'status' => ['required', Rule::in(DashboardCatalog::BRANCH_STATUSES)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'pin_top' => ['nullable', 'integer', 'between:0,100'],
            'pin_left' => ['nullable', 'integer', 'between:0,100'],
        ];
    }
}
