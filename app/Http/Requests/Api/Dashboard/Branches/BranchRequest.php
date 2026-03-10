<?php

namespace App\Http\Requests\Api\Dashboard\Branches;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;

abstract class BranchRequest extends DashboardFormRequest
{
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'pin_top.between' => 'The map pin top position must be between :min and :max.',
            'pin_left.between' => 'The map pin left position must be between :min and :max.',
        ]);
    }

    public function attributes(): array
    {
        return [
            'company_id' => 'company',
            'name' => 'branch name',
            'code' => 'branch code',
            'address' => 'branch address',
            'status' => 'branch status',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'pin_top' => 'map pin top position',
            'pin_left' => 'map pin left position',
        ];
    }
}
