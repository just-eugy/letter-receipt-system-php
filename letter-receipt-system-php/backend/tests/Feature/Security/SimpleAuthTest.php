<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimpleAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_auth_works(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'password' => bcrypt('password123'),
            'display_name' => 'Test User',
            'role' => 'RECEPTIONIST',
            'active' => true,
        ]);

        $token = $user->createToken('test')->plainTextToken;
        
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJson(['username' => 'testuser', 'role' => 'RECEPTIONIST']);
    }
}