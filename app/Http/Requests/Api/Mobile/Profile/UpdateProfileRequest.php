<?php

namespace App\Http\Requests\Api\Mobile\Profile;

use App\Http\Requests\Api\Mobile\MobileFormRequest;

class UpdateProfileRequest extends MobileFormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'email'],
            'phone_number' => ['nullable', 'string', 'min:6', 'max:30'],
        ];
    }

    public function attributes(): array
    {
        return [
            'full_name' => 'full name',
            'email' => 'email address',
            'phone_number' => 'phone number',
        ];
    }
}
