<?php

namespace App\Http\Requests\Api\Dashboard\Auth;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;

class ForgotPasswordRequest extends DashboardFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }

    public function attributes(): array
    {
        return [
            'email' => 'email address',
        ];
    }
}
