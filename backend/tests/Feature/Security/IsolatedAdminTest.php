<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IsolatedAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'password' => bcrypt('AdminPasswordNow123!'),
            'display_name' => 'Test Admin',
            'role' => 'ADMIN',
            'active' => true,
        ]);

        $this->adminToken = $this->admin->createToken('test')->plainTextToken;
    }

    public function test_admin_endpoints(): void
    {
        $endpoints = [
            ['POST', '/api/admin/logout'],
            ['GET', '/api/admin/me'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            // Admin OK
            $response = $this->json(strtoupper($method), $uri, [], [
                'Authorization' => 'Bearer ' . $this->adminToken,
            ]);
            echo "Admin $method $uri - Status: " . $response->status() . PHP_EOL;
            $this->assertNotEquals(403, $response->status(), "Failed for $method $uri: " . $response->getContent());
        }
    }
}