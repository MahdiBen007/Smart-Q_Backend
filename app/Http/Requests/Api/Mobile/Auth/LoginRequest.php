<?php

namespace App\Http\Requests\Api\Mobile\Auth;

use App\Http\Requests\Api\Mobile\MobileFormRequest;

class LoginRequest extends MobileFormRequest
{
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'min:3', 'max:120'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'identifier.required' => 'Please enter your email or phone number.',
            'identifier.min' => 'The login identifier is too short.',
            'identifier.max' => 'The login identifier is too long.',
            'password.required' => 'Please enter your password.',
            'password.min' => 'The password must be at least :min characters.',
        ]);
    }

    public function attributes(): array
    {
        return [
            'identifier' => 'email or phone number',
            'password' => 'password',
        ];
    }
}
