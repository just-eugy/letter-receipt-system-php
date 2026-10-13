<?php

namespace Tests\Feature\Security;

use App\Models\LetterDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MinimalAuthTest extends TestCase
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

    public function test_report_endpoints_require_receptionist_or_admin(): void
    {
        $endpoints = [
            ['POST', '/api/reception/reports/generate'],
            ['GET', '/api/reception/reports/export/csv'],
            ['GET', '/api/reception/reports/export/excel'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            // No auth
            $response = $this->json(strtoupper($method), $uri);
            $response->assertStatus(401);

            // Receptionist OK
            $response = $this->json(strtoupper($method), $uri, [], [
                'Authorization' => 'Bearer ' . $this->receptionistToken,
            ]);
            $this->assertNotEquals(401, $response->status());
            $this->assertNotEquals(403, $response->status());

            // Admin OK
            $response = $this->json(strtoupper($method), $uri, [], [
                'Authorization' => 'Bearer ' . $this->adminToken,
            ]);
            $this->assertNotEquals(401, $response->status());
            $this->assertNotEquals(403, $response->status());
        }
    }
}