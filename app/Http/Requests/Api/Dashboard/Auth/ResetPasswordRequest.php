<?php

namespace App\Http\Requests\Api\Dashboard\Auth;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;

class ResetPasswordRequest extends DashboardFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'token.required' => 'A password reset token is required.',
            'password.min' => 'The password must be at least :min characters.',
            'password.confirmed' => 'The password confirmation does not match.',
        ]);
    }

    public function attributes(): array
    {
        return [
            'email' => 'email address',
            'token' => 'reset token',
            'password' => 'password',
        ];
    }
}
