<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Auth\AuthenticationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'auth.basic' => \App\Http\Middleware\HttpBasicAuth::class,
        ]);

        // Global middleware
        $middleware->prepend(\App\Http\Middleware\SecurityHeaders::class);

        // CORS for API routes
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // Rate limiting
        $middleware->rateLimiting([
            'login' => \Illuminate\Routing\Middleware\ThrottleRequests::class.':login',
            'delivery-create' => \Illuminate\Routing\Middleware\ThrottleRequests::class.':delivery-create',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Validation errors - 400
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'timestamp' => now()->toISOString(),
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                ], 400);
            }
        });

        // Not found - 404
        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'timestamp' => now()->toISOString(),
                    'message' => 'Not found',
                ], 404);
            }
        });

        // Unauthorized - 401
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'timestamp' => now()->toISOString(),
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        // Forbidden - 403
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'timestamp' => now()->toISOString(),
                    'message' => 'Forbidden.',
                ], 403);
            }
        });
    })->create();
