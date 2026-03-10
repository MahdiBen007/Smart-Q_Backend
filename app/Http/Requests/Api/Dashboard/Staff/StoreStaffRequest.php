<?php

namespace App\Http\Requests\Api\Dashboard\Staff;

use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone_number' => ['nullable', 'string', 'max:50', 'unique:users,phone_number'],
            'branch_id' => ['required', 'exists:branches,id'],
            'role' => ['required', Rule::in(DashboardCatalog::STAFF_ROLES)],
            'status' => ['required', Rule::in(DashboardCatalog::STAFF_STATUSES)],
            'avatar_url' => ['nullable', 'string'],
        ];
    }
}
