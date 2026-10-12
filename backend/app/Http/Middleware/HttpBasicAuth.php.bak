<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class HttpBasicAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip if already authenticated via request user resolver
        if ($request->user()) {
            return $next($request);
        }

        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Basic ')) {
            return $this->unauthorizedResponse();
        }

        $credentials = base64_decode(substr($authHeader, 6));

        if ($credentials === false || !str_contains($credentials, ':')) {
            return $this->unauthorizedResponse();
        }

        [$username, $password] = explode(':', $credentials, 2);

        $user = \App\Models\User::where('username', $username)->first();

        if (!$user || !$user->active || !Hash::check($password, $user->password)) {
            return $this->unauthorizedResponse();
        }

        // Set user resolver for stateless authentication
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        // Also log in to default guard for any middleware that uses Auth::user()
        Auth::login($user);

        return $next($request);
    }

    /**
     * Return 401 Unauthorized with WWW-Authenticate header.
     */
    private function unauthorizedResponse(): Response
    {
        return response()->json([
            'timestamp' => now()->toISOString(),
            'message' => 'Unauthenticated.',
        ], 401)->header('WWW-Authenticate', 'Basic realm="Letter Receipt API"');
    }
}