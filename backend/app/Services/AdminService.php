<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminService
{
    /**
     * Authenticate admin user and create API token.
     */
    public function login(string $username, string $password): array
    {
        $user = User::where('username', $username)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'username' => ['Invalid username or password.'],
            ]);
        }

        if (!$user->active) {
            throw ValidationException::withMessages([
                'username' => ['Account is disabled.'],
            ]);
        }

        if (!Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Invalid username or password.'],
            ]);
        }

        // Create API token
        $token = $user->createToken('api-token')->plainTextToken;

        return [
            'user' => [
                'username' => $user->username,
                'displayName' => $user->display_name,
                'role' => $user->role,
            ],
            'token' => $token,
        ];
    }

    /**
     * Logout user (revoke token).
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    /**
     * Get current user info.
     */
    public function me(User $user): array
    {
        return [
            'username' => $user->username,
            'displayName' => $user->display_name,
            'role' => $user->role,
        ];
    }
}