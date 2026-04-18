<?php

namespace App\Http\Requests\Api\Mobile\Auth;

use App\Http\Requests\Api\Mobile\MobileFormRequest;

class ForgotPasswordRequest extends MobileFormRequest
{
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'min:3', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'identifier.required' => 'Please enter your email or phone number.',
        ]);
    }

    public function attributes(): array
    {
        return [
            'identifier' => 'email or phone number',
        ];
    }
}
