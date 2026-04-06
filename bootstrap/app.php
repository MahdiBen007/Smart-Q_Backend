<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
        $middleware->appendToGroup('api', \Illuminate\Http\Middleware\HandleCors::class);

        $middleware->alias([
            'jwt.auth' => \App\Http\Middleware\AuthenticateJwt::class,
            'dashboard.role' => \App\Http\Middleware\EnsureDashboardRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $resolveApiInfrastructureMessage = static function (\Throwable $exception): string {
            $message = strtolower($exception->getMessage());

            if (
                str_contains($message, 'base table or view not found')
                || str_contains($message, "doesn't exist")
                || str_contains($message, 'unknown table')
                || str_contains($message, 'no such table')
            ) {
                return 'The database is not initialized yet. Please run the migrations and create the first admin account.';
            }

            if (
                str_contains($message, 'unknown database')
                || str_contains($message, 'connection refused')
                || str_contains($message, 'access denied')
                || str_contains($message, 'could not find driver')
                || str_contains($message, 'server has gone away')
                || str_contains($message, 'too many connections')
            ) {
                return 'The application cannot connect to the database right now. Please verify the database configuration.';
            }

            return 'A server error occurred while processing your request.';
        };

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $errors = $exception->errors();
            $message = collect($errors)->flatten()->first() ?: 'The given data was invalid.';

            return response()->json([
                'message' => $message,
                'errors' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        });

        $exceptions->render(function (QueryException $exception, Request $request) use ($resolveApiInfrastructureMessage) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $resolveApiInfrastructureMessage($exception),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        });

        $exceptions->render(function (\PDOException $exception, Request $request) use ($resolveApiInfrastructureMessage) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $resolveApiInfrastructureMessage($exception),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        });

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! $request->is('api/*') || $exception instanceof ValidationException) {
                return null;
            }

            if ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();

                if ($status < Response::HTTP_INTERNAL_SERVER_ERROR) {
                    $message = $exception->getMessage();

                    if ($message === '') {
                        $message = match ($status) {
                            Response::HTTP_UNAUTHORIZED => 'Authentication is required to continue.',
                            Response::HTTP_FORBIDDEN => 'You do not have permission to perform this action.',
                            Response::HTTP_NOT_FOUND => 'The requested resource could not be found.',
                            default => 'Unable to complete this request.',
                        };
                    }

                    return response()->json([
                        'message' => $message,
                    ], $status);
                }
            }

            return response()->json([
                'message' => 'A server error occurred while processing your request.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
