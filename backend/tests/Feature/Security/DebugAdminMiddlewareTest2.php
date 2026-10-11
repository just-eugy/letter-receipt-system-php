<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugAdminMiddlewareTest2 extends TestCase
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

    public function test_debug_admin_me(): void
    {
        // Test admin accessing /api/admin/me
        $response = $this->getJson('/api/admin/me', [], [
            'Authorization' => 'Bearer ' . $this->adminToken,
        ]);
        
        echo "Admin me status: " . $response->status() . "\n";
        echo "Admin me body: " . $response->getContent() . "\n";
    }
}