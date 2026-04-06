<?php

namespace App\Http\Requests\Api\Dashboard\Auth;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends DashboardFormRequest
{
    public function rules(): array
    {
        $user = $this->user();

        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($user?->getKey()),
            ],
            'phone_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('users', 'phone_number')->ignore($user?->getKey()),
            ],
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
