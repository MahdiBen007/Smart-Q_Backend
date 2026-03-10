<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

abstract class DashboardApiController extends Controller
{
    protected function respond(
        mixed $data = null,
        ?string $message = null,
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $payload = [];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    protected function rememberPayload(string $key, callable $callback, int $seconds = 15): mixed
    {
        if (app()->environment('testing')) {
            return $callback();
        }

        return Cache::remember($key, now()->addSeconds($seconds), $callback);
    }
}
