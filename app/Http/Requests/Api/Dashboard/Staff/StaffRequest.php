<?php

namespace App\Http\Requests\Api\Dashboard\Staff;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;

abstract class StaffRequest extends DashboardFormRequest
{
    public function attributes(): array
    {
        return [
            'name' => 'staff member name',
            'email' => 'staff email address',
            'phone_number' => 'staff phone number',
            'branch_id' => 'branch',
            'role' => 'staff role',
            'status' => 'staff status',
            'avatar_url' => 'avatar URL',
            'avatar' => 'avatar image',
        ];
    }
}
