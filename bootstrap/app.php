<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\UseBearerTokenFromCookie::class,
        ]);

        // Blanket ceiling for API traffic, keyed per authenticated user (see
        // AppServiceProvider). Individual routes tighten this where it counts
        // -- /auth/login and /auth/register carry much stricter limiters.
        $middleware->api(append: [
            'throttle:api',
            \App\Http\Middleware\EnforceMaintenanceMode::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (!$request->expectsJson() && !$request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors'  => $e->errors(),
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            if ($e instanceof ModelNotFoundException) {
                $model = class_basename($e->getModel());
                return response()->json(['message' => "{$model} not found."], 404);
            }

            if ($e instanceof HttpException) {
                // Keep the exception's own headers -- a 429 from the rate
                // limiter carries Retry-After, which the SPA needs to tell
                // the user how long to wait.
                return response()->json(
                    ['message' => $e->getMessage() ?: 'HTTP error.'],
                    $e->getStatusCode(),
                    $e->getHeaders(),
                );
            }

            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            $message = config('app.debug') ? $e->getMessage() : 'Server error. Please try again later.';

            return response()->json(['message' => $message], $status);
        });
    })->create();
