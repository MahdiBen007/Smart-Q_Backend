<?php

namespace App\Http\Requests\Api\Dashboard\Staff;

class UploadStaffAvatarRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'avatar' => ['nullable', 'file', 'image', 'max:2048'],
            'avatar_url' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'avatar.max' => 'The avatar image may not be greater than :max kilobytes.',
        ]);
    }
}
