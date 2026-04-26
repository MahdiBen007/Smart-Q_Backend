<?php

namespace App\Http\Controllers\Api\Dashboard\Auth;

use App\Enums\EmploymentStatus;
use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\Dashboard\Auth\LoginRequest;
use App\Http\Requests\Api\Dashboard\Auth\ResetPasswordRequest;
use App\Http\Requests\Api\Dashboard\Auth\UpdateProfileRequest;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Support\Auth\JwtTokenService;
use App\Support\Dashboard\DashboardFormatting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;

class AuthController extends DashboardApiController
{
    public function __construct(
        protected JwtTokenService $jwtTokenService,
    ) {}

    public function login(LoginRequest $request)
    {
        if (! User::query()->exists()) {
            throw ValidationException::withMessages([
                'email' => ['No user accounts are available yet. Please create the first admin account.'],
            ]);
        }

        $user = User::query()
            ->with(['userRoles', 'staffMember.branch.company', 'preference'])
            ->where('email', $request->string('email')->value())
            ->first();

        if (! $user || ! Hash::check($request->string('password')->value(), $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (
            ! $user->is_active
            || $user->staffMember?->employment_status === EmploymentStatus::Inactive
        ) {
            throw ValidationException::withMessages([
                'email' => ['This account is disabled. Please contact your administrator.'],
            ]);
        }

        $remember = $request->boolean('remember');
        $issuedToken = $this->jwtTokenService->issueAccessToken(
            $user,
            $request->ip(),
            $request->userAgent(),
            $remember ? (int) config('jwt.remember_ttl_minutes') : null,
        );

        return $this->respondWithAuthCookie([
            'expires_at' => $issuedToken['expires_at']->toIso8601String(),
            'user' => $this->transformUser($user->fresh(['userRoles', 'staffMember.branch.company', 'preference'])),
        ], $issuedToken['token'], $remember, 'Authenticated successfully.');
    }

    public function logout(Request $request)
    {
        $payload = $request->attributes->get('jwt_payload', []);

        if (isset($payload['jti'])) {
            $this->jwtTokenService->revokeByJti($payload['jti']);
        }

        return $this->respond(message: 'Logged out successfully.')
            ->withCookie($this->expireAuthCookie());
    }

    public function me(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond(
            $this->transformUser($user->loadMissing(['userRoles', 'staffMember.branch.company', 'preference']))
        );
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        if (isset($validated['email']) || array_key_exists('phone_number', $validated)) {
            $user->update([
                'email' => $validated['email'] ?? $user->email,
                'phone_number' => array_key_exists('phone_number', $validated)
                    ? $validated['phone_number']
                    : $user->phone_number,
            ]);
        }

        if ($user->staffMember && isset($validated['full_name'])) {
            $user->staffMember->update([
                'full_name' => $validated['full_name'],
            ]);
        }

        return $this->respond(
            $this->transformUser($user->fresh(['userRoles', 'staffMember.branch.company', 'preference'])),
            'Profile updated successfully.'
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
                    'logo_url' => $staffMember->branch->logo_url,
                ] : null,
                'company' => $staffMember->company ? [
                    'id' => $staffMember->company->getKey(),
                    'name' => $staffMember->company->company_name,
                ] : null,
            ] : null,
            'preferences' => $user->preference?->dashboard_settings,
        ];
    }

    protected function respondWithAuthCookie(
        mixed $data,
        string $token,
        bool $remember,
        ?string $message = null,
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        return $this->respond($data, $message, $status, $meta)
            ->withCookie($this->makeAuthCookie($token, $remember));
    }

    protected function makeAuthCookie(string $token, bool $remember): HttpCookie
    {
        $ttlMinutes = max(1, (int) config($remember ? 'jwt.remember_ttl_minutes' : 'jwt.ttl_minutes'));

        return Cookie::make(
            name: (string) config('jwt.cookie_name'),
            value: $token,
            minutes: $remember ? $ttlMinutes : 0,
            path: (string) config('jwt.cookie_path', '/'),
            domain: config('jwt.cookie_domain'),
            secure: (bool) config('jwt.cookie_secure'),
            httpOnly: true,
            raw: false,
            sameSite: (string) config('jwt.cookie_same_site', 'lax'),
        );
    }

    protected function expireAuthCookie(): HttpCookie
    {
        return Cookie::forget(
            name: (string) config('jwt.cookie_name'),
            path: (string) config('jwt.cookie_path', '/'),
            domain: config('jwt.cookie_domain'),
        );
    }
}
