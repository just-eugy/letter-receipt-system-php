<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugReportLoopTest extends TestCase
{
    use RefreshDatabase;

    protected string $receptionistToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receptionist = \App\Models\User::factory()->create([
            'username' => 'receptionist',
            'password' => bcrypt('password123'),
            'display_name' => 'Test Receptionist',
            'role' => 'RECEPTIONIST',
            'active' => true,
        ]);

        $this->receptionistToken = $this->receptionist->createToken('test')->plainTextToken;
    }

    public function test_report_loop_with_auth_then_no_auth(): void
    {
        $endpoints = [
            ['POST', '/api/reception/reports/generate'],
            ['GET', '/api/reception/reports/export/csv'],
            ['GET', '/api/reception/reports/export/excel'],
        ];

        // First, make an authenticated request
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson('/api/reception/reports/generate', []);
        
        echo "After auth request - Response status: " . $response->status() . PHP_EOL;

        // Now make unauthenticated requests in a loop
        foreach ($endpoints as [$method, $uri]) {
            $response = $this->json(strtoupper($method), $uri);
            echo "No auth - $method $uri - Status: " . $response->status() . PHP_EOL;
        }
    }
}