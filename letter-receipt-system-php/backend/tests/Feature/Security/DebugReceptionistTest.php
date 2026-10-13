<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugReceptionistTest extends TestCase
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

    public function test_debug_receptionist_token(): void
    {
        echo "Receptionist token: " . $this->receptionistToken . PHP_EOL;
        
        // Check if token exists in database
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->receptionist->id,
            'name' => 'test',
        ]);
        
        // Try with the token
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/deliveries/pending');

        echo "Response status: " . $response->status() . PHP_EOL;
        echo "Response body: " . $response->getContent() . PHP_EOL;
        
        $response->assertStatus(200);
    }
}