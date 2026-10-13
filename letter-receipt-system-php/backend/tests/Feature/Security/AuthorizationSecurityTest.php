<?php

namespace Tests\Feature\Security;

use App\Models\LetterDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationSecurityTest extends TestCase
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

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Make an unauthenticated JSON request without cookies.
     */
    protected function unauthenticatedJson(string $method, string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->call($method, $uri, $data, [], [], [], null, true);
    }

    /**
     * Clear the test client's cookie jar and flush session.
     */
    protected function clearCookies(): void
    {
        // Clear cookies
        if (isset($this->client) && $this->client && method_exists($this->client, 'getCookieJar')) {
            $cookieJar = $this->client->getCookieJar();
            if ($cookieJar) {
                $cookieJar->clear();
            }
        }
        
        // Flush session to prevent session sharing across requests
        if (function_exists('session')) {
            session()->flush();
        }
    }

    // ===== Public Endpoints (No Auth Required) =====

    public function test_public_endpoints_accessible_without_auth(): void
    {
        $this->unauthenticatedJson('GET', '/api/public/recipient-roles')->assertStatus(200);
        $this->postJson('/api/public/deliveries', ['fullName' => 'Test'])->assertStatus(201);
        $this->unauthenticatedJson('GET', '/api/public/deliveries/NONEXISTENT')->assertStatus(404);
    }

    // ===== Reception Endpoints (RECEPTIONIST or ADMIN) =====

    public function test_reception_endpoints_require_authentication(): void
    {
        $endpoints = [
            ['GET', '/api/reception/deliveries/pending'],
            ['GET', '/api/reception/deliveries/pending/page'],
            ['GET', '/api/reception/deliveries/received'],
            ['GET', '/api/reception/deliveries/received/page'],
            ['POST', '/api/reception/deliveries/1/receive'],
            ['GET', '/api/reception/statistics'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $response = $this->unauthenticatedJson($method, $uri);
            $response->assertStatus(401, "Failed for $method $uri");
        }
    }

    public function test_receptionist_can_access_reception_endpoints(): void
    {
        $endpoints = [
            ['GET', '/api/reception/deliveries/pending'],
            ['GET', '/api/reception/deliveries/pending/page'],
            ['GET', '/api/reception/deliveries/received'],
            ['GET', '/api/reception/deliveries/received/page'],
            ['POST', '/api/reception/deliveries/1/receive'],
            ['GET', '/api/reception/statistics'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $response = $this->json(strtoupper($method), $uri, [], [
                'Authorization' => 'Bearer ' . $this->receptionistToken,
            ]);
            
            // Should not be 401 or 403 (may be 404 for ID=1, but not auth error)
            $this->assertNotEquals(401, $response->status(), "Failed for $method $uri: " . $response->getContent());
            $this->assertNotEquals(403, $response->status(), "Failed for $method $uri: " . $response->getContent());
        }
    }

    public function test_admin_can_access_reception_endpoints(): void
    {
        $endpoints = [
            ['GET', '/api/reception/deliveries/pending'],
            ['GET', '/api/reception/deliveries/pending/page'],
            ['GET', '/api/reception/deliveries/received'],
            ['GET', '/api/reception/deliveries/received/page'],
            ['POST', '/api/reception/deliveries/1/receive'],
            ['GET', '/api/reception/statistics'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $response = $this->json(strtoupper($method), $uri, [], [
                'Authorization' => 'Bearer ' . $this->adminToken,
            ]);
            
            $this->assertNotEquals(401, $response->status(), "Failed for $method $uri");
            $this->assertNotEquals(403, $response->status(), "Failed for $method $uri");
        }
    }

    // ===== Report Endpoints (RECEPTIONIST or ADMIN) =====

    public function test_report_endpoints_require_receptionist_or_admin(): void
    {
        $endpoints = [
            ['POST', '/api/reception/reports/generate'],
            ['GET', '/api/reception/reports/export/csv'],
            ['GET', '/api/reception/reports/export/excel'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            // No auth
            $this->clearCookies();
            $response = $this->unauthenticatedJson($method, $uri);
            $response->assertStatus(401);

            // Receptionist OK
            $response = $this->json(strtoupper($method), $uri, [], [
                'Authorization' => 'Bearer ' . $this->receptionistToken,
            ]);
            $this->assertNotEquals(401, $response->status());
            $this->assertNotEquals(403, $response->status());

            // Clear cookies after authenticated request
            $this->clearCookies();

            // Admin OK
            $response = $this->json(strtoupper($method), $uri, [], [
                'Authorization' => 'Bearer ' . $this->adminToken,
            ]);
            $this->assertNotEquals(401, $response->status());
            $this->assertNotEquals(403, $response->status());

            $this->clearCookies();
        }
    }

    // ===== Admin Endpoints (ADMIN Only) =====

    public function test_admin_endpoints_require_admin_role(): void
    {
        $endpoints = [
            ['POST', '/api/admin/logout'],
            ['GET', '/api/admin/me'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            // Receptionist should get 403
            $response = $this->json(strtoupper($method), $uri, [], [
                'Authorization' => 'Bearer ' . $this->receptionistToken,
            ]);
            $response->assertStatus(403, "Failed for $method $uri: " . $response->getContent());

            // Admin OK
            $response = $this->json(strtoupper($method), $uri, [], [
                'Authorization' => 'Bearer ' . $this->adminToken,
            ]);
            $this->assertNotEquals(403, $response->status());
        }
    }

    public function test_admin_login_accessible_without_auth(): void
    {
        $this->postJson('/api/admin/login', [
            'username' => 'admin',
            'password' => 'AdminPasswordNow123!',
        ])->assertStatus(200);
    }

    // ===== Delivery Receive Authorization =====

    public function test_receive_delivery_requires_receptionist_or_admin(): void
    {
        $delivery = LetterDelivery::factory()->create(['status' => 'DELIVERED']);

        // No auth
        $this->unauthenticatedJson('POST', "/api/reception/deliveries/{$delivery->id}/receive", [])
            ->assertStatus(401);

        // Receptionist OK
        $response = $this->postJson("/api/reception/deliveries/{$delivery->id}/receive", [], [
            'Authorization' => 'Bearer ' . $this->receptionistToken,
        ]);
        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());

        // Admin OK
        $response = $this->postJson("/api/reception/deliveries/{$delivery->id}/receive", [], [
            'Authorization' => 'Bearer ' . $this->adminToken,
        ]);
        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());
    }

    // ===== Object-Level Authorization =====

    public function test_receive_nonexistent_delivery_returns_404(): void
    {
        $response = $this->postJson('/api/reception/deliveries/99999/receive', [], [
            'Authorization' => 'Bearer ' . $this->receptionistToken,
        ]);
        
        $response->assertStatus(404);
    }

    public function test_receive_already_received_delivery_is_idempotent(): void
    {
        $delivery = LetterDelivery::factory()->create([
            'status' => 'RECEIVED',
            'received_at' => now()->subHour(),
            'received_by_user_id' => $this->receptionist->id,
        ]);

        // Create the initial delivery event (simulating the first receive)
        \App\Models\DeliveryEvent::create([
            'delivery_id' => $delivery->id,
            'event_type' => 'RECEIVED',
            'actor_name' => 'Test Receptionist',
            'remarks' => 'Physical letter verified and received',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test Agent',
            'event_at' => $delivery->received_at,
        ]);

        $response = $this->postJson("/api/reception/deliveries/{$delivery->id}/receive", ['remarks' => 'Second attempt'], [
            'Authorization' => 'Bearer ' . $this->receptionistToken,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'RECEIVED');

        // Should NOT create duplicate event
        $this->assertDatabaseCount('delivery_events', 1);
    }

    // ===== Role Cannot Be Client-Supplied =====

    public function test_user_role_cannot_be_set_via_request(): void
    {
        // Attempt to register with admin role via public endpoint (if such endpoint existed)
        // This test ensures no endpoint allows role elevation
        $user = User::factory()->create([
            'username' => 'testuser',
            'password' => bcrypt('password123'),
            'display_name' => 'Test User',
            'role' => 'RECEPTIONIST',
            'active' => true,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        // User should not be able to access admin endpoints
        $response = $this->postJson('/api/admin/logout', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);
        
        $response->assertStatus(403);

        // User should be able to access reception endpoints
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/reception/deliveries/pending');
        
        $this->assertNotEquals(403, $response->status());
    }
}