<?php

namespace App\Http\Controllers\Api\Mobile\Profile;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Http\Requests\Api\Mobile\Profile\UpdateProfileRequest;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfileController extends MobileApiController
{
    public function uploadAvatar(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $avatarBase64 = trim((string) $request->input('avatar_base64', ''));
        $avatarUrl = trim((string) $request->input('avatar_url', ''));

        if ($avatarBase64 === '' && $avatarUrl === '') {
            return $this->respond(message: 'Avatar data is required.', status: 422);
        }

        $customer = $user->customer ?? Customer::query()->create([
            'user_id' => $user->getKey(),
            'full_name' => 'Customer',
            'phone_number' => $user->phone_number ?? '',
            'email_address' => $user->email,
        ]);

        $resolvedAvatarUrl = $this->persistAvatar(
            user: $user,
            customer: $customer,
            avatarBase64: $avatarBase64,
            avatarUrl: $avatarUrl,
        );
        if ($resolvedAvatarUrl === null) {
            return $this->respondValidationError(
                'Avatar payload is invalid.',
                ['avatar_base64' => ['Avatar payload is invalid.']]
            );
        }

        $customer->update(['avatar_url' => $resolvedAvatarUrl]);

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

    protected function persistAvatar(
        User $user,
        Customer $customer,
        string $avatarBase64,
        string $avatarUrl,
    ): ?string {
        if ($avatarUrl !== '') {
            return $avatarUrl;
        }

        $mime = 'image/jpeg';
        $rawPayload = $avatarBase64;
        if (Str::startsWith($avatarBase64, 'data:')) {
            if (! preg_match('/^data:(?<mime>image\\/[\\w.+-]+);base64,(?<data>.+)$/', $avatarBase64, $matches)) {
                return null;
            }
            $mime = (string) ($matches['mime'] ?? $mime);
            $rawPayload = (string) ($matches['data'] ?? '');
        }

        $binary = base64_decode($rawPayload, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        $extension = match (Str::lower($mime)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        $previousPath = $this->publicDiskPathFromUrl((string) ($customer->avatar_url ?? ''));
        $fileName = sprintf(
            '%s_%s.%s',
            (string) $user->getKey(),
            now()->format('YmdHisv'),
            $extension
        );
        $path = 'avatars/customers/'.$fileName;

        Storage::disk('public')->put($path, $binary);

        if ($previousPath !== null && $previousPath !== '' && $previousPath !== $path) {
            Storage::disk('public')->delete($previousPath);
        }

        return url(Storage::disk('public')->url($path));
    }

    protected function publicDiskPathFromUrl(string $avatarUrl): ?string
    {
        $normalized = trim($avatarUrl);
        if ($normalized === '') {
            return null;
        }

        $path = parse_url($normalized, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            $path = $normalized;
        }

        $path = trim($path);
        if (Str::startsWith($path, '/storage/')) {
            return ltrim(Str::after($path, '/storage/'), '/');
        }

        if (Str::startsWith($path, 'storage/')) {
            return ltrim(Str::after($path, 'storage/'), '/');
        }

        if (Str::startsWith($path, '/avatars/')) {
            return ltrim($path, '/');
        }

        if (Str::startsWith($path, 'avatars/')) {
            return $path;
        }

        return null;
    }
}
