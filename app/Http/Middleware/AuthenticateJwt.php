<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Auth\JwtTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateJwt
{
    public function __construct(
        protected JwtTokenService $jwtTokenService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return new JsonResponse([
                'message' => 'Missing bearer token.',
            ], 401);
        }

        try {
            $payload = $this->jwtTokenService->validateAccessToken($token);
            $user = User::query()->findOrFail($payload['sub']);
        } catch (Throwable $exception) {
            return new JsonResponse([
                'message' => 'Unauthenticated.',
                'meta' => [
                    'reason' => $exception->getMessage(),
                ],
            ], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('jwt_payload', $payload);
        $request->attributes->set('jwt_token', $token);

        return $next($request);
    }
}
