<?php

namespace App\Http\Requests\Api\Dashboard\Counters;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;

abstract class CounterRequest extends DashboardFormRequest
{
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'branch_id.exists' => 'The selected counter branch is invalid.',
        ]);
    }

    public function attributes(): array
    {
        return [
            'branch_id' => 'branch',
            'counter_code' => 'counter code',
            'name' => 'counter name',
            'status' => 'counter status',
            'display_order' => 'display order',
        ];
    }
}
