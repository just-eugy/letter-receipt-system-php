<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugCookieTest extends TestCase
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

    public function test_debug_cookies(): void
    {
        // Request 1: No auth
        $response1 = $this->postJson('/api/reception/reports/generate', []);
        echo "Request 1 (no auth): " . $response1->status() . PHP_EOL;
        echo "Request 1 cookies: " . json_encode($response1->headers->getCookies()) . PHP_EOL;
        
        // Request 2: With auth
        $response2 = $this->postJson('/api/reception/reports/generate', [], [
            'Authorization' => 'Bearer ' . $this->receptionistToken,
        ]);
        echo "Request 2 (with auth): " . $response2->status() . PHP_EOL;
        echo "Request 2 cookies: " . json_encode($response2->headers->getCookies()) . PHP_EOL;
        
        // Request 3: No auth
        $response3 = $this->getJson('/api/reception/reports/export/csv');
        echo "Request 3 (no auth): " . $response3->status() . PHP_EOL;
        echo "Request 3 cookies sent: " . json_encode($this->getClientCookies()) . PHP_EOL;
    }
    
    private function getClientCookies(): array
    {
        if ($this->client) {
            $cookieJar = $this->client->getCookieJar();
            if ($cookieJar) {
                $cookies = [];
                foreach ($cookieJar->all() as $cookie) {
                    $cookies[$cookie->getName()] = $cookie->getValue();
                }
                return $cookies;
            }
        }
        return [];
    }
}