<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimpleCookieTest extends TestCase
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

    public function test_simple(): void
    {
        // Request 1: No auth
        $r1 = $this->postJson('/api/reception/reports/generate', []);
        echo "R1: " . $r1->status() . "\n";
        
        // Request 2: With auth
        $r2 = $this->postJson('/api/reception/reports/generate', [], [
            'Authorization' => 'Bearer ' . $this->receptionistToken,
        ]);
        echo "R2: " . $r2->status() . "\n";
        
        // Request 3: No auth
        $r3 = $this->getJson('/api/reception/reports/export/csv');
        echo "R3: " . $r3->status() . "\n";
    }
}