<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDashboardRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Authentication is required to continue.');

        $normalizedRoles = collect($roles)
            ->map(fn (string $role) => strtolower(trim($role)))
            ->filter()
            ->values();

        if ($normalizedRoles->isEmpty()) {
            return $next($request);
        }

        $hasRequiredRole = $user->userRoles()
            ->whereIn('role_name', $normalizedRoles->all())
            ->exists();

        abort_unless($hasRequiredRole, Response::HTTP_FORBIDDEN, 'You do not have permission to perform this action.');

        return $next($request);
    }
}
