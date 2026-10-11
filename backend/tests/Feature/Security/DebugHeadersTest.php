<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugHeadersTest extends TestCase
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

    public function test_debug_headers(): void
    {
        // Request 1: No auth
        $response1 = $this->postJson('/api/reception/reports/generate', []);
        echo "Request 1 (no auth): " . $response1->status() . PHP_EOL;
        
        // Request 2: With auth
        $response2 = $this->postJson('/api/reception/reports/generate', [], [
            'Authorization' => 'Bearer ' . $this->receptionistToken,
        ]);
        echo "Request 2 (with auth): " . $response2->status() . PHP_EOL;
        
        // Request 3: No auth - check what headers are sent
        $response3 = $this->getJson('/api/reception/reports/export/csv');
        echo "Request 3 (no auth): " . $response3->status() . PHP_EOL;
        
        // Check the test client's default headers
        if (property_exists($this, 'headers')) {
            echo "Test client headers: " . json_encode($this->headers) . PHP_EOL;
        }
        
        // Check the client's default headers
        if ($this->client && method_exists($this->client, 'getDefaultHeaders')) {
            echo "Client default headers: " . json_encode($this->client->getDefaultHeaders()) . PHP_EOL;
        }
    }
}