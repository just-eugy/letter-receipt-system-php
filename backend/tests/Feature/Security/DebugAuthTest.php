<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_debug_token(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'password' => bcrypt('password123'),
            'display_name' => 'Test User',
            'role' => 'RECEPTIONIST',
            'active' => true,
        ]);

        $token = $user->createToken('test')->plainTextToken;
        
        // Debug: Check if token exists in database
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'test',
        ]);
        
        // Check the token format
        echo "Token: " . $token . PHP_EOL;
        echo "Token length: " . strlen($token) . PHP_EOL;
        echo "Token prefix: " . substr($token, 0, 10) . PHP_EOL;
        
        // Try with the token
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/user');

        // Debug: Check response
        echo "Response status: " . $response->status() . PHP_EOL;
        echo "Response body: " . $response->getContent() . PHP_EOL;
        
        $response->assertStatus(200);
    }
}