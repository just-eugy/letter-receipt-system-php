<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'timestamp' => now()->toISOString(),
                'message' => 'Invalid username or password.',
            ], Response::HTTP_UNAUTHORIZED);
        }
    }

    /**
     * Admin logout - revokes API token.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->adminService->logout($request->user());

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