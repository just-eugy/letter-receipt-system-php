<?php

namespace Tests\Feature\Security;

use App\Models\LetterDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugAuthorizationTest extends TestCase
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

    public function test_debug_receptionist_access(): void
    {
        echo "Receptionist token: " . $this->receptionistToken . PHP_EOL;
        
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/deliveries/pending');

        echo "Response status: " . $response->status() . PHP_EOL;
        echo "Response body: " . $response->getContent() . PHP_EOL;
        
        $response->assertStatus(200);
    }

    public function test_debug_admin_access(): void
    {
        echo "Admin token: " . $this->adminToken . PHP_EOL;
        
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
            ->getJson('/api/reception/deliveries/pending');

        echo "Response status: " . $response->status() . PHP_EOL;
        echo "Response body: " . $response->getContent() . PHP_EOL;
        
        $response->assertStatus(200);
    }

    public function test_debug_receptionist_report(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson('/api/reception/reports/generate', []);

        echo "Response status: " . $response->status() . PHP_EOL;
        echo "Response body: " . $response->getContent() . PHP_EOL;
        
        $response->assertStatus(200);
    }

    public function test_debug_admin_logout(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
            ->postJson('/api/admin/logout');

        echo "Response status: " . $response->status() . PHP_EOL;
        echo "Response body: " . $response->getContent() . PHP_EOL;
        
        $response->assertStatus(200);
    }
}