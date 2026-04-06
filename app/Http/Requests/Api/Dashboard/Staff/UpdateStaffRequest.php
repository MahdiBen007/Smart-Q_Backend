<?php

namespace App\Http\Requests\Api\Dashboard\Staff;

use App\Models\StaffMember;
use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends StaffRequest
{
    public function rules(): array
    {
        /** @var StaffMember $staff */
        $staff = $this->route('staff');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($staff->user_id)],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('users', 'phone_number')->ignore($staff->user_id)],
            'service_id' => ['sometimes', 'nullable', $this->serviceExistsRule()],
            'role' => ['sometimes', Rule::in(DashboardCatalog::STAFF_ROLES)],
            'status' => ['sometimes', Rule::in(DashboardCatalog::STAFF_STATUSES)],
            'avatar_url' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(fn ($innerValidator) => $this->validateServiceAssignment($innerValidator, true));
    }
}
