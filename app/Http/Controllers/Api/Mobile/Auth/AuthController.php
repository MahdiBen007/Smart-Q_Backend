<?php

namespace App\Http\Controllers\Api\Mobile\Auth;

use App\Enums\UserRoleName;
use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Http\Requests\Api\Mobile\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\Mobile\Auth\LoginRequest;
use App\Http\Requests\Api\Mobile\Auth\RegisterRequest;
use App\Http\Requests\Api\Mobile\Auth\ResetPasswordRequest;
use App\Models\Customer;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Auth\JwtTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends MobileApiController
{
    public function __construct(
        protected JwtTokenService $jwtTokenService,
    ) {}

    public function login(LoginRequest $request)
    {
        $identifier = trim($request->string('identifier')->value());
        $normalizedEmail = mb_strtolower($identifier);

        $user = User::query()
            ->with(['customer', 'userRoles'])
            ->where(function ($query) use ($identifier, $normalizedEmail) {
                $query
                    ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                    ->orWhere('phone_number', $identifier);
            })
            ->first();

        if (! $user || ! Hash::check($request->string('password')->value(), $user->password_hash)) {
            return $this->respondValidationError(
                'The provided credentials are incorrect.',
                ['identifier' => ['The provided credentials are incorrect.']]
            );
        }

        if (! $user->is_active) {
            return $this->respondValidationError(
                'This account is disabled.',
                ['identifier' => ['This account is disabled.']]
            );
        }

        $hasCustomerRole = $user->userRoles
            ->pluck('role_name')
            ->contains(fn ($role) => $role?->value === UserRoleName::Customer->value);

        if (! $hasCustomerRole && ! $user->customer) {
            return $this->respondValidationError(
                'This account is not registered as a customer.',
                ['identifier' => ['This account is not registered as a customer.']]
            );
        }

        $issuedToken = $this->jwtTokenService->issueAccessToken(
            $user,
            $request->ip(),
            $request->userAgent(),
        );

        return $this->respond([
            'token' => $issuedToken['token'],
            'expires_at' => $issuedToken['expires_at']->toIso8601String(),
            'user' => $this->transformUser($user->fresh(['customer'])),
        ], 'Authenticated successfully.');
    }

    public function register(RegisterRequest $request)
    {
        $email = trim($request->string('email')->value());
        $phone = trim($request->string('phone_number')->value());
        $normalizedEmail = mb_strtolower($email);
        $requestedType = trim($request->string('user_type')->value());
        $hasSpecialNeedsFlag = $request->has('is_special_needs');
        $isSpecialNeeds = $hasSpecialNeedsFlag
            ? $request->boolean('is_special_needs')
            : null;
        $normalizedRequestedType = mb_strtolower($requestedType);

        $userType = $isSpecialNeeds !== null
            ? ($isSpecialNeeds ? 'special_needs' : 'regular')
            : ($normalizedRequestedType === 'special_needs' ? 'special_needs' : 'regular');

        if ($email !== '' && User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail])->exists()) {
            return $this->respondValidationError(
                'Email already exists.',
                ['email' => ['Email already exists.']]
            );
        }

        if (User::query()->where('phone_number', $phone)->exists()) {
            return $this->respondValidationError(
                'Phone number already exists.',
                ['phone_number' => ['Phone number already exists.']]
            );
        }

        $user = User::query()->create([
            'email' => $email !== '' ? $email : null,
            'phone_number' => $phone,
            'password_hash' => $request->string('password')->value(),
            'user_type' => $userType,
            'is_active' => true,
        ]);

        UserRole::query()->create([
            'user_id' => $user->getKey(),
            'role_name' => UserRoleName::Customer,
        ]);

        Customer::query()->create([
            'user_id' => $user->getKey(),
            'full_name' => $request->string('full_name')->value(),
            'phone_number' => $phone,
            'email_address' => $email !== '' ? $email : null,
        ]);

        $issuedToken = $this->jwtTokenService->issueAccessToken(
            $user,
            $request->ip(),
            $request->userAgent(),
        );

        return $this->respond([
            'token' => $issuedToken['token'],
            'expires_at' => $issuedToken['expires_at']->toIso8601String(),
            'user' => $this->transformUser($user->fresh(['customer'])),
        ], 'Account created successfully.', 201);
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $identifier = trim($request->string('identifier')->value());
        $normalizedEmail = mb_strtolower($identifier);

        $user = User::query()
            ->where(function ($query) use ($identifier, $normalizedEmail) {
                $query
                    ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                    ->orWhere('phone_number', $identifier);
            })
            ->first();

        $plainToken = null;

        if ($user) {
            PasswordResetToken::query()
                ->where('user_id', $user->getKey())
                ->whereNull('used_at')
                ->delete();

            $plainToken = Str::random(64);

            PasswordResetToken::create([
                'user_id' => $user->getKey(),
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addMinutes(30),
            ]);
        }

        $data = app()->environment(['local', 'testing']) && $plainToken !== null
            ? ['reset_token' => $plainToken]
            : null;

        return $this->respond(
            $data,
            'If the account exists, a password reset token has been generated.'
        );
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        $identifier = trim($request->string('identifier')->value());
        $normalizedEmail = mb_strtolower($identifier);

        $user = User::query()
            ->where(function ($query) use ($identifier, $normalizedEmail) {
                $query
                    ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                    ->orWhere('phone_number', $identifier);
            })
            ->first();

        if (! $user) {
            return $this->respondValidationError(
                'Unable to complete the password reset request.',
                ['identifier' => ['Unable to complete the password reset request.']]
            );
        }

        $tokenRecord = PasswordResetToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (
            ! $tokenRecord
            || ! hash_equals($tokenRecord->token_hash, hash('sha256', $request->string('token')->value()))
        ) {
            return $this->respondValidationError(
                'The password reset token is invalid or expired.',
                ['token' => ['The password reset token is invalid or expired.']]
            );
        }

        $user->update([
            'password_hash' => $request->string('password')->value(),
        ]);

        $tokenRecord->update([
            'used_at' => now(),
        ]);

        $this->jwtTokenService->revokeAllForUser($user);

        return $this->respond(message: 'Password reset successfully.');
    }

    public function me(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond($this->transformUser($user->loadMissing(['customer'])));
    }

    public function logout(Request $request)
    {
        $payload = $request->attributes->get('jwt_payload', []);

        if (isset($payload['jti'])) {
            $this->jwtTokenService->revokeByJti($payload['jti']);
        }

        return $this->respond(message: 'Logged out successfully.');
    }

    protected function transformUser(User $user): array
    {
        $customer = $user->customer;

        return [
            'id' => $user->getKey(),
            'name' => $customer?->full_name ?? '',
            'email' => $user->email ?? $customer?->email_address ?? '',
            'phone' => $user->phone_number ?? $customer?->phone_number ?? '',
            'user_type' => $user->user_type ?? 'regular',
            'is_special_needs' => ($user->user_type ?? 'regular') === 'special_needs',
        ];
    }
}
