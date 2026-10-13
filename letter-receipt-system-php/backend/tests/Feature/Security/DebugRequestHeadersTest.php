<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugRequestHeadersTest extends TestCase
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

    public function test_debug_request_headers(): void
    {
        // Request 1: No auth
        $response1 = $this->postJson('/api/reception/reports/generate', []);
        echo "Request 1 (no auth): " . $response1->status() . PHP_EOL;
        
        // Request 2: With auth
        $response2 = $this->postJson('/api/reception/reports/generate', [], [
            'Authorization' => 'Bearer ' . $this->receptionistToken,
        ]);
        echo "Request 2 (with auth): " . $response2->status() . PHP_EOL;
        
        // Request 3: No auth - check what headers the test client has
        echo "Test client headers before request 3: " . json_encode($this->headers) . PHP_EOL;
        
        // Manually make request without using json() helper
        $response3 = $this->call('GET', '/api/reception/reports/export/csv', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        echo "Request 3 (no auth, manual call): " . $response3->status() . PHP_EOL;
    }
}