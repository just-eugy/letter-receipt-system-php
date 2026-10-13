<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugCallWithoutCookies extends TestCase
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
        // Request 1: No auth, with call() and withoutCookies = true
        $r1 = $this->call('POST', '/api/reception/reports/generate', [], [], [], [], null, true);
        echo "R1 (call without cookies): " . $r1->status() . "\n";
        
        // Request 2: With auth, normal postJson
        $r2 = $this->postJson('/api/reception/reports/generate', [], [
            'Authorization' => 'Bearer ' . $this->receptionistToken,
        ]);
        echo "R2 (with auth): " . $r2->status() . "\n";
        
        // Request 3: No auth, call with withoutCookies = true
        $r3 = $this->call('GET', '/api/reception/reports/export/csv', [], [], [], [], null, true);
        echo "R3 (call without cookies): " . $r3->status() . "\n";
    }
}