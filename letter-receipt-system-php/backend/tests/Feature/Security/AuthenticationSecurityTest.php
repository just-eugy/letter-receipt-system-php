<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $receptionist;
    protected string $adminToken;
    protected string $receptionistToken;

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

        $this->receptionist = User::factory()->create([
            'username' => 'receptionist',
            'password' => bcrypt('password123'),
            'display_name' => 'Test Receptionist',
            'role' => 'RECEPTIONIST',
            'active' => true,
        ]);

        $this->adminToken = $this->admin->createToken('test')->plainTextToken;
        $this->receptionistToken = $this->receptionist->createToken('test')->plainTextToken;
    }

    // ===== Login Tests =====

    public function test_login_success_returns_user_info(): void
    {
        $response = $this->postJson('/api/admin/login', [
            'username' => 'admin',
            'password' => 'AdminPasswordNow123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['username', 'displayName', 'role'])
            ->assertJson(['username' => 'admin', 'role' => 'ADMIN']);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/admin/login', [
            'username' => 'admin',
            'password' => 'wrong',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Invalid username or password.')
            ->assertJsonMissing(['token']);
    }

    public function test_login_fails_for_inactive_user(): void
    {
        User::factory()->create([
            'username' => 'inactive',
            'password' => bcrypt('password123'),
            'display_name' => 'Inactive',
            'role' => 'ADMIN',
            'active' => false,
        ]);

        $response = $this->postJson('/api/admin/login', [
            'username' => 'inactive',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Account is disabled.');
    }

    public function test_login_validates_required_fields(): void
    {
        $response = $this->postJson('/api/admin/login', []);
        $response->assertStatus(400)
            ->assertJsonPath('errors.username.0', 'Username is required.');

        $response = $this->postJson('/api/admin/login', ['username' => 'admin']);
        $response->assertStatus(400)
            ->assertJsonPath('errors.password.0', 'Password is required.');
    }

    public function test_login_rate_limited(): void
    {
        // Make 8 requests (limit is 8 per minute)
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/admin/login', [
                'username' => 'admin',
                'password' => 'wrong',
            ]);
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

    // ===== Logout Tests =====

    public function test_logout_revokes_token(): void
    {
        // Create a fresh token for this test
        $logoutToken = $this->admin->createToken('logout-test')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $logoutToken])
            ->postJson('/api/admin/logout');

        $response->assertStatus(200);

        // Token should be revoked - check database
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->admin->id,
            'name' => 'logout-test',
        ]);
    }

    public function test_logout_requires_authentication(): void
    {
        $response = $this->postJson('/api/admin/logout');
        $response->assertStatus(401);
    }

    // ===== Current User Tests =====

    public function test_me_returns_current_user_with_token(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
            ->getJson('/api/admin/me');

        $response->assertStatus(200)
            ->assertJson(['username' => 'admin', 'role' => 'ADMIN']);
    }

    public function test_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/me');
        $response->assertStatus(401);
    }

    // ===== HTTP Basic Auth Tests =====

    public function test_http_basic_auth_works(): void
    {
        $credentials = base64_encode('admin:AdminPasswordNow123!');

        $response = $this->withHeaders(['Authorization' => 'Basic ' . $credentials])
            ->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJson(['username' => 'admin', 'role' => 'ADMIN']);
    }

    public function test_http_basic_auth_fails_with_invalid_credentials(): void
    {
        $credentials = base64_encode('admin:wrong');

        $response = $this->withHeaders(['Authorization' => 'Basic ' . $credentials])
            ->getJson('/api/user');

        $response->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Basic realm="Letter Receipt API"');
    }

    public function test_http_basic_auth_fails_for_inactive_user(): void
    {
        User::factory()->create([
            'username' => 'inactive',
            'password' => bcrypt('password123'),
            'display_name' => 'Inactive',
            'role' => 'ADMIN',
            'active' => false,
        ]);

        $credentials = base64_encode('inactive:password123');

        $response = $this->withHeaders(['Authorization' => 'Basic ' . $credentials])
            ->getJson('/api/user');

        $response->assertStatus(401);
    }
}