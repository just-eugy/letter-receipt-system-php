<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugAdminRoleTest extends TestCase
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

    public function test_debug_admin_access(): void
    {
        // Test admin accessing admin endpoint
        $response = $this->postJson('/api/admin/logout', [], [
            'Authorization' => 'Bearer ' . $this->adminToken,
        ]);
        
        echo "Admin logout status: " . $response->status() . PHP_EOL;
        echo "Admin logout body: " . $response->getContent() . PHP_EOL;
        
        $response = $this->json('GET', '/api/admin/me', [], [
            'Authorization' => 'Bearer ' . $this->adminToken,
        ]);
        
        echo "Admin me status: " . $response->status() . PHP_EOL;
        echo "Admin me body: " . $response->getContent() . PHP_EOL;
    }
}