<?php

namespace App\Http\Requests\Api\Dashboard\Staff;

class UpdateStaffBranchRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'exists:branches,id'],
        ];
    }
}
