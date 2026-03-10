<?php

namespace App\Http\Requests\Api\Dashboard\WalkIns;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;

class EscalateWalkInRequest extends DashboardFormRequest
{
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'notes' => 'escalation notes',
        ];
    }
}
