<?php

namespace App\Http\Requests\Api\Dashboard\Staff;

use App\Models\Counter;
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
            'counter_id' => 'counter',
            'role' => 'staff role',
            'status' => 'staff status',
            'avatar_url' => 'avatar URL',
            'avatar' => 'avatar image',
        ];
    }

    protected function validateCounterAssignment(Validator $validator, bool $isUpdate = false): void
    {
        $role = (string) ($this->input('role') ?: ($isUpdate ? $this->currentStaffRoleLabel() : ''));
        $branchId = (string) ($this->input('branch_id') ?: ($isUpdate ? $this->currentStaffBranchId() : ''));
        $counterId = $this->input('counter_id');

        if ($role !== 'Staff') {
            return;
        }

        if (! filled($counterId)) {
            $validator->errors()->add('counter_id', 'The counter field is required for staff members.');
            return;
        }

        if (! filled($branchId)) {
            return;
        }

        $belongsToBranch = Counter::query()
            ->whereKey($counterId)
            ->where('branch_id', $branchId)
            ->exists();

        if (! $belongsToBranch) {
            $validator->errors()->add('counter_id', 'The selected counter does not belong to the selected branch.');
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

        return match ($role) {
            'admin' => 'Admin',
            'manager' => 'Branch Admin',
            default => 'Staff',
        };
    }

    protected function currentStaffBranchId(): ?string
    {
        /** @var StaffMember|null $staff */
        $staff = $this->route('staff');

        return $staff?->branch_id;
    }
}
