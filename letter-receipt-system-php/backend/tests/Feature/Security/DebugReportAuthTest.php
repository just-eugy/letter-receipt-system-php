<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugReportAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_debug_report_no_auth(): void
    {
        // Test POST /api/reception/reports/generate without auth
        $response = $this->postJson('/api/reception/reports/generate', []);
        
        echo "Response status: " . $response->status() . PHP_EOL;
        echo "Response body: " . $response->getContent() . PHP_EOL;
        
        $response->assertStatus(401);
    }
}