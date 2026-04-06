<?php

namespace App\Http\Requests\Api\Dashboard\Auth;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;

class LoginRequest extends DashboardFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'password.min' => 'The password must be at least :min characters.',
        ]);
    }

    public function attributes(): array
    {
        return [
            'email' => 'email address',
            'password' => 'password',
            'remember' => 'remember me preference',
        ];
    }
}
