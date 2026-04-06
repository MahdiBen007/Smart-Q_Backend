<?php

namespace App\Support\Auth;

use App\Models\JwtToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use RuntimeException;

class JwtTokenService
{
    public function issueAccessToken(
        User $user,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?int $ttlMinutes = null,
    ): array
    {
        $issuedAt = CarbonImmutable::now();
        $resolvedTtl = max(1, $ttlMinutes ?? (int) Config::get('jwt.ttl_minutes', 120));
        $expiresAt = $issuedAt->addMinutes($resolvedTtl);
        $jti = (string) Str::uuid();

        $payload = [
            'iss' => Config::get('jwt.issuer'),
            'iat' => $issuedAt->timestamp,
            'nbf' => $issuedAt->timestamp,
            'exp' => $expiresAt->timestamp,
            'sub' => $user->getKey(),
            'jti' => $jti,
            'typ' => 'access',
        ];

        $token = $this->encode($payload);

        JwtToken::create([
            'user_id' => $user->getKey(),
            'jti' => $jti,
            'token_type' => 'access',
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'jti' => $jti,
        ];
    }

    public function validateAccessToken(string $token): array
    {
        $payload = $this->decode($token);

        if (($payload['typ'] ?? null) !== 'access') {
            throw new RuntimeException('Unsupported token type.');
        }

        $tokenRecord = JwtToken::query()
            ->where('jti', $payload['jti'] ?? null)
            ->first();

        if (! $tokenRecord) {
            throw new RuntimeException('Token record not found.');
        }

        if ($tokenRecord->revoked_at !== null) {
            throw new RuntimeException('Token has been revoked.');
        }

        if ($tokenRecord->expires_at->isPast()) {
            throw new RuntimeException('Token has expired.');
        }

        $tokenRecord->forceFill([
            'last_used_at' => now(),
        ])->save();

        return $payload;
    }

    public function revokeByJti(string $jti): void
    {
        JwtToken::query()
            ->where('jti', $jti)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeAllForUser(User $user): void
    {
        JwtToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function encode(array $payload): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), $this->resolveSecret(), true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    public function decode(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new RuntimeException('Malformed token.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;

        $header = json_decode($this->base64UrlDecode($encodedHeader), true, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode($this->base64UrlDecode($encodedPayload), true, 512, JSON_THROW_ON_ERROR);

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new RuntimeException('Unsupported signing algorithm.');
        }

        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $this->resolveSecret(), true)
        );

        if (! hash_equals($expectedSignature, $encodedSignature)) {
            throw new RuntimeException('Token signature is invalid.');
        }

        $now = CarbonImmutable::now()->timestamp;

        if (($payload['nbf'] ?? 0) > $now) {
            throw new RuntimeException('Token is not yet valid.');
        }

        if (($payload['exp'] ?? 0) <= $now) {
            throw new RuntimeException('Token has expired.');
        }

        return $payload;
    }

    protected function resolveSecret(): string
    {
        $secret = (string) Config::get('jwt.secret', '');

        if ($secret === '') {
            throw new RuntimeException('JWT secret is missing.');
        }

        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $secret;
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('Unable to decode token segment.');
        }

        return $decoded;
    }
}
