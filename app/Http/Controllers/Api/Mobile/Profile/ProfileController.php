<?php

namespace App\Http\Controllers\Api\Mobile\Profile;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Http\Requests\Api\Mobile\Profile\UpdateProfileRequest;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;

class ProfileController extends MobileApiController
{
    public function uploadAvatar(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $avatarBase64 = (string) $request->input('avatar_base64', '');
        $avatarUrl = (string) $request->input('avatar_url', '');

        if ($avatarBase64 === '' && $avatarUrl === '') {
            return $this->respond(message: 'Avatar data is required.', status: 422);
        }

        $customer = $user->customer ?? Customer::query()->create([
            'user_id' => $user->getKey(),
            'full_name' => 'Customer',
            'phone_number' => $user->phone_number ?? '',
            'email_address' => $user->email,
        ]);

        $customer->update([
            'avatar_url' => $avatarUrl !== '' ? $avatarUrl : $avatarBase64,
        ]);

        return $this->respond([
            'avatar_url' => $customer->avatar_url,
        ], 'Avatar updated successfully.');
    }

    public function show(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond($this->transformProfile($user->loadMissing('customer')));
    }

    public function update(UpdateProfileRequest $request)
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $user->update([
            'email' => $validated['email'] ?? $user->email,
            'phone_number' => $validated['phone_number'] ?? $user->phone_number,
        ]);

        $customer = $user->customer ?? Customer::query()->create([
            'user_id' => $user->getKey(),
            'full_name' => $validated['full_name'] ?? 'Customer',
            'phone_number' => $validated['phone_number'] ?? $user->phone_number ?? '',
            'email_address' => $validated['email'] ?? $user->email,
        ]);

        if (isset($validated['full_name'])) {
            $customer->update([
                'full_name' => $validated['full_name'],
            ]);
        }

        if (array_key_exists('phone_number', $validated)) {
            $customer->update([
                'phone_number' => $validated['phone_number'],
            ]);
        }

        if (array_key_exists('email', $validated)) {
            $customer->update([
                'email_address' => $validated['email'],
            ]);
        }

        return $this->respond(
            $this->transformProfile($user->fresh('customer')),
            'Profile updated successfully.'
        );
    }

    protected function transformProfile(User $user): array
    {
        $customer = $user->customer;

        return [
            'name' => $customer?->full_name ?? '',
            'email' => $user->email ?? $customer?->email_address ?? '',
            'phone' => $user->phone_number ?? $customer?->phone_number ?? '',
            'avatar_url' => $customer?->avatar_url ?? '',
        ];
    }
}
