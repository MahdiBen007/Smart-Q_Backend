<?php

namespace App\Http\Requests\Api\Mobile\Auth;

use App\Http\Requests\Api\Mobile\MobileFormRequest;

class ResetPasswordRequest extends MobileFormRequest
{
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'min:3', 'max:120'],
            'token' => ['required', 'string', 'min:4', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'max:255', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'identifier.required' => 'Please enter your email or phone number.',
            'token.required' => 'Please enter the reset token.',
            'password.required' => 'Please enter a new password.',
            'password.confirmed' => 'Password confirmation does not match.',
        ]);
    }

    public function attributes(): array
    {
        return [
            'identifier' => 'email or phone number',
            'token' => 'reset token',
            'password' => 'password',
        ];
    }
}
