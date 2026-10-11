<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestCallWithoutCookies extends TestCase
{
    use RefreshDatabase;

    protected User $receptionist;
    protected string $receptionistToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receptionist = User::factory()->create([
            'username' => 'receptionist',
            'password' => bcrypt('password123'),
            'display_name' => 'Test Receptionist',
            'role' => 'RECEPTIONIST',
            'active' => true,
        ]);

        $this->receptionistToken = $this->receptionist->createToken('test')->plainTextToken;
    }

    public function test_call_without_cookies(): void
    {
        // Test call() with withoutCookies = true
        $response = $this->call('POST', '/api/reception/reports/generate', [], [], [], [], null, true);
        echo "Status: " . $response->status() . "\n";
        $response->assertStatus(401);
    }
}