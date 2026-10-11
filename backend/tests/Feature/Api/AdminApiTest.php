<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $receptionist;
    protected string $adminToken;

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
    }

    // ===== POST /admin/login =====

    public function test_login_success(): void
    {
        $response = $this->postJson('/api/admin/login', [
            'username' => 'admin',
            'password' => 'AdminPasswordNow123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'username',
                'displayName',
                'role',
            ])
            ->assertJson([
                'username' => 'admin',
                'displayName' => 'Test Admin',
                'role' => 'ADMIN',
            ]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/admin/login', [
            'username' => 'admin',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Invalid username or password.');
    }

    public function test_login_fails_with_missing_username(): void
    {
        $response = $this->postJson('/api/admin/login', [
            'password' => 'AdminPasswordNow123!',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('errors.username.0', 'Username is required.');
    }

    public function test_login_fails_with_missing_password(): void
    {
        $response = $this->postJson('/api/admin/login', [
            'username' => 'admin',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('errors.password.0', 'Password is required.');
    }

    public function test_login_fails_for_inactive_user(): void
    {
        $inactiveUser = User::factory()->create([
            'username' => 'inactive',
            'password' => bcrypt('password123'),
            'display_name' => 'Inactive User',
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

    // ===== POST /admin/logout =====

    public function test_logout_success(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
            ->postJson('/api/admin/logout');

        $response->assertStatus(200);

        // Token should be revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->admin->id,
        ]);
    }

    public function test_logout_requires_authentication(): void
    {
        $response = $this->postJson('/api/admin/logout');

        $response->assertStatus(401);
    }

    // ===== GET /admin/me =====

    public function test_me_returns_current_user(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
            ->getJson('/api/admin/me');

        $response->assertStatus(200)
            ->assertJson([
                'username' => 'admin',
                'displayName' => 'Test Admin',
                'role' => 'ADMIN',
            ]);
    }

    public function test_me_works_for_receptionist(): void
    {
        $token = $this->receptionist->createToken('test')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/admin/me');

        $response->assertStatus(200)
            ->assertJson([
                'username' => 'receptionist',
                'displayName' => 'Test Receptionist',
                'role' => 'RECEPTIONIST',
            ]);
    }

    public function test_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/me');

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}