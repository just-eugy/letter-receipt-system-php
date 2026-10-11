<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugReportAuth2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users like AuthorizationSecurityTest does
        $this->admin = \App\Models\User::factory()->create([
            'username' => 'admin',
            'password' => bcrypt('AdminPasswordNow123!'),
            'display_name' => 'Test Admin',
            'role' => 'ADMIN',
            'active' => true,
        ]);

        $this->receptionist = \App\Models\User::factory()->create([
            'username' => 'receptionist',
            'password' => bcrypt('password123'),
            'display_name' => 'Test Receptionist',
            'role' => 'RECEPTIONIST',
            'active' => true,
        ]);

        $this->adminToken = $this->admin->createToken('test')->plainTextToken;
        $this->receptionistToken = $this->receptionist->createToken('test')->plainTextToken;
    }

    public function test_debug_report_no_auth(): void
    {
        // Test POST /api/reception/reports/generate without auth
        $response = $this->postJson('/api/reception/reports/generate', []);
        
        echo "Response status: " . $response->status() . PHP_EOL;
        echo "Response body: " . $response->getContent() . PHP_EOL;
        
        $response->assertStatus(401);
    }
}