<?php

namespace App\Http\Controllers\Api\Dashboard\Auth;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\Dashboard\Auth\LoginRequest;
use App\Http\Requests\Api\Dashboard\Auth\ResetPasswordRequest;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Support\Auth\JwtTokenService;
use App\Support\Dashboard\DashboardFormatting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends DashboardApiController
{
    public function __construct(
        protected JwtTokenService $jwtTokenService,
    ) {}

    public function login(LoginRequest $request)
    {
        $user = User::query()
            ->with(['userRoles', 'staffMember.branch.company', 'preference'])
            ->where('email', $request->string('email')->value())
            ->first();

        if (! $user || ! $user->is_active || ! Hash::check($request->string('password')->value(), $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $issuedToken = $this->jwtTokenService->issueAccessToken(
            $user,
            $request->ip(),
            $request->userAgent(),
        );

        return $this->respond([
            'access_token' => $issuedToken['token'],
            'token_type' => 'Bearer',
            'expires_at' => $issuedToken['expires_at']->toIso8601String(),
            'user' => $this->transformUser($user->fresh(['userRoles', 'staffMember.branch.company', 'preference'])),
        ], 'Authenticated successfully.');
    }

    public function logout(Request $request)
    {
        $payload = $request->attributes->get('jwt_payload', []);

        if (isset($payload['jti'])) {
            $this->jwtTokenService->revokeByJti($payload['jti']);
        }

        return $this->respond(message: 'Logged out successfully.');
    }

    public function me(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond(
            $this->transformUser($user->loadMissing(['userRoles', 'staffMember.branch.company', 'preference']))
        );
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $user = User::query()
            ->where('email', $request->string('email')->value())
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
        $user = User::query()
            ->where('email', $request->string('email')->value())
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Unable to complete the password reset request.'],
            ]);
        }

        $tokenRecord = PasswordResetToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $tokenRecord || ! hash_equals($tokenRecord->token_hash, hash('sha256', $request->string('token')->value()))) {
            throw ValidationException::withMessages([
                'token' => ['The password reset token is invalid or expired.'],
            ]);
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

    protected function transformUser(User $user): array
    {
        $roles = $user->userRoles
            ->pluck('role_name')
            ->map(fn ($role) => $role->value)
            ->values()
            ->all();

        $staffMember = $user->staffMember;

        return [
            'id' => $user->getKey(),
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'is_active' => (bool) $user->is_active,
            'roles' => $roles,
            'staff_member' => $staffMember ? [
                'id' => $staffMember->getKey(),
                'full_name' => $staffMember->full_name,
                'initials' => DashboardFormatting::initials($staffMember->full_name),
                'status' => DashboardFormatting::employmentStatusLabel($staffMember->employment_status->value),
                'display_staff_code' => $staffMember->display_staff_code,
                'is_online' => (bool) $staffMember->is_online,
                'branch' => $staffMember->branch ? [
                    'id' => $staffMember->branch->getKey(),
                    'name' => $staffMember->branch->branch_name,
                    'code' => $staffMember->branch->branch_code,
                ] : null,
                'company' => $staffMember->company ? [
                    'id' => $staffMember->company->getKey(),
                    'name' => $staffMember->company->company_name,
                ] : null,
            ] : null,
            'preferences' => $user->preference?->dashboard_settings,
        ];
    }
}
