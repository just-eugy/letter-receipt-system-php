<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate limiter for login endpoints (8 requests per minute per IP)
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(8)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'timestamp' => now()->toISOString(),
                        'message' => 'Too many login attempts. Try again in a minute.',
                    ], 429)->header('Retry-After', 60);
                });
        });

        // Rate limiter for public delivery creation (40 requests per minute per IP)
        RateLimiter::for('delivery-create', function (Request $request) {
            return Limit::perMinute(40)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'timestamp' => now()->toISOString(),
                        'message' => 'Too many delivery submissions. Try again in a minute.',
                    ], 429)->header('Retry-After', 60);
                });
        });

        // Global API rate limiter (optional - can be enabled via env)
        if (config('app.rate_limit_enabled', true)) {
            RateLimiter::for('api', function (Request $request) {
                return Limit::perMinute(100)
                    ->by($request->ip())
                    ->response(function () {
                        return response()->json([
                            'timestamp' => now()->toISOString(),
                            'message' => 'Too many requests. Try again later.',
                        ], 429)->header('Retry-After', 60);
                    });
            });
        }
    }
}
