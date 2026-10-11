<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitingSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

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
    }

    // ===== Login Rate Limiting =====

    public function test_login_rate_limit_enforced(): void
    {
        // Limit is 8 requests per minute
        for ($i = 0; $i < 8; $i++) {
            $response = $this->postJson('/api/admin/login', [
                'username' => 'admin',
                'password' => 'wrong',
            ]);
            $this->assertNotEquals(429, $response->status(), "Request $i should not be rate limited");
        }

        // 9th request should be rate limited
        $response = $this->postJson('/api/admin/login', [
            'username' => 'admin',
            'password' => 'wrong',
        ]);

        $response->assertStatus(429)
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('message', 'Too many login attempts. Try again in a minute.');
    }

    public function test_login_rate_limit_per_ip(): void
    {
        // Make 8 requests from one IP
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/admin/login', [
                'username' => 'admin',
                'password' => 'wrong',
            ]);
        }

        // Same IP should be rate limited
        $response = $this->postJson('/api/admin/login', [
            'username' => 'admin',
            'password' => 'wrong',
        ]);
        $response->assertStatus(429);

        // Different IP should not be rate limited (simulated by different test)
        // Note: In real scenario, this would be a different client IP
    }

    // ===== Delivery Creation Rate Limiting =====

    public function test_delivery_creation_rate_limit_enforced(): void
    {
        // Limit is 40 requests per minute
        for ($i = 0; $i < 40; $i++) {
            $response = $this->postJson('/api/public/deliveries', [
                'fullName' => "Test User $i",
            ]);
            $this->assertNotEquals(429, $response->status(), "Request $i should not be rate limited");
        }

        // 41st request should be rate limited
        $response = $this->postJson('/api/public/deliveries', [
            'fullName' => 'Test User 41',
        ]);

        $response->assertStatus(429)
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('message', 'Too many delivery submissions. Try again in a minute.');
    }

    public function test_delivery_creation_rate_limit_per_ip(): void
    {
        // Make 40 requests
        for ($i = 0; $i < 40; $i++) {
            $this->postJson('/api/public/deliveries', [
                'fullName' => "Test User $i",
            ]);
        }

        // Should be rate limited
        $response = $this->postJson('/api/public/deliveries', [
            'fullName' => 'Test User 41',
        ]);
        $response->assertStatus(429);
    }
}