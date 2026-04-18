<?php

namespace App\Http\Requests\Api\Mobile\Auth;

use App\Http\Requests\Api\Mobile\MobileFormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends MobileFormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone_number' => ['required', 'string', 'min:6', 'max:30'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'user_type' => ['nullable', 'string', Rule::in(['regular', 'special_needs'])],
            'is_special_needs' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'full_name.required' => 'Please enter your full name.',
            'email.email' => 'Please enter a valid email address.',
            'phone_number.required' => 'Please enter your phone number.',
            'password.required' => 'Please enter a password.',
            'user_type.in' => 'Selected user type is invalid.',
        ]);
    }

    public function attributes(): array
    {
        return [
            'full_name' => 'full name',
            'email' => 'email address',
            'phone_number' => 'phone number',
            'password' => 'password',
            'user_type' => 'user type',
        ];
    }
}
