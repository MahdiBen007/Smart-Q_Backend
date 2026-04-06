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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:8'],
            'phone_number' => ['nullable', 'string', 'max:50', 'unique:users,phone_number'],
            'branch_id' => ['required', $this->branchExistsRule()],
            'service_id' => ['nullable', $this->serviceExistsRule()],
            'role' => ['required', Rule::in(DashboardCatalog::STAFF_ROLES)],
            'status' => ['required', Rule::in(DashboardCatalog::STAFF_STATUSES)],
            'avatar_url' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(fn ($innerValidator) => $this->validateServiceAssignment($innerValidator));
    }
}
