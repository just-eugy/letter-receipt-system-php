<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AdminController extends Controller
{
    public function __construct(
        private readonly AdminService $adminService
    ) {}

    /**
     * Admin login - creates API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->adminService->login(
                $request->validated('username'),
                $request->validated('password')
            );

            return response()->json([
                'username' => $result['user']['username'],
                'displayName' => $result['user']['displayName'],
                'role' => $result['user']['role'],
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $message = 'Invalid username or password.';

            // Check if it's an inactive user error
            if (isset($errors['username']) && in_array('Account is disabled.', $errors['username'])) {
                $message = 'Account is disabled.';
            }

            return response()->json([
                'timestamp' => now()->toISOString(),
                'message' => $message,
            ], Response::HTTP_UNAUTHORIZED);
        }
    }

    /**
     * Admin logout - revokes API token.
     */
    public function logout(Request $request): JsonResponse
    {
        // Revoke the current access token
        $request->user()->currentAccessToken()?->delete();

        return response()->json(null, Response::HTTP_OK);
    }

    /**
     * Get current authenticated admin user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'timestamp' => now()->toISOString(),
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json($this->adminService->me($user));
    }
}