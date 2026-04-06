<?php

namespace App\Http\Requests\Api\Dashboard\Staff;

use App\Models\Service;
use App\Models\StaffMember;
use App\Http\Requests\Api\Dashboard\DashboardFormRequest;
use Illuminate\Validation\Validator;

abstract class StaffRequest extends DashboardFormRequest
{
    public function attributes(): array
    {
        return [
            'name' => 'staff member name',
            'email' => 'staff email address',
            'password' => 'staff password',
            'password_confirmation' => 'password confirmation',
            'phone_number' => 'staff phone number',
            'branch_id' => 'branch',
            'service_id' => 'service',
            'role' => 'staff role',
            'status' => 'staff status',
            'avatar_url' => 'avatar URL',
            'avatar' => 'avatar image',
        ];
    }

    protected function validateServiceAssignment(Validator $validator, bool $isUpdate = false): void
    {
        $role = (string) ($this->input('role') ?: ($isUpdate ? $this->currentStaffRoleLabel() : ''));
        $branchId = (string) ($this->input('branch_id') ?: ($isUpdate ? $this->currentStaffBranchId() : ''));
        $serviceId = $this->input('service_id');

        if ($role !== 'Staff') {
            return;
        }

        if (! filled($serviceId)) {
            $validator->errors()->add('service_id', 'The service field is required for staff members.');
            return;
        }

        if (! filled($branchId)) {
            return;
        }

        $belongsToBranch = Service::query()
            ->whereKey($serviceId)
            ->where(function ($query) use ($branchId): void {
                $query
                    ->where('branch_id', $branchId)
                    ->orWhereHas('branches', fn ($branchQuery) => $branchQuery->whereKey($branchId));
            })
            ->exists();

        if (! $belongsToBranch) {
            $validator->errors()->add('service_id', 'The selected service does not belong to the selected branch.');
        }
    }

    protected function currentStaffRoleLabel(): ?string
    {
        /** @var StaffMember|null $staff */
        $staff = $this->route('staff');

        if (! $staff) {
            return null;
        }

        $role = $staff->user?->userRoles()->value('role_name');

        return $role === 'admin' ? 'Admin' : 'Staff';
    }

    protected function currentStaffBranchId(): ?string
    {
        /** @var StaffMember|null $staff */
        $staff = $this->route('staff');

        return $staff?->branch_id;
    }
}
