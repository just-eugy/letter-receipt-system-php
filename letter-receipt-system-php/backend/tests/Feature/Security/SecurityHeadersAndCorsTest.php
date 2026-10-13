<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersAndCorsTest extends TestCase
{
    use RefreshDatabase;

    // ===== Security Headers Tests =====

    public function test_security_headers_present_on_api_responses(): void
    {
        $response = $this->getJson('/api/public/recipient-roles');

        $response->assertStatus(200);

        // Check security headers
        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertEquals('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertEquals('1; mode=block', $response->headers->get('X-XSS-Protection'));
        $this->assertEquals('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('geolocation=()', $response->headers->get('Permissions-Policy'));
        
        // Server header should be removed
        $this->assertNull($response->headers->get('Server'));
        $this->assertNull($response->headers->get('X-Powered-By'));
    }

    public function test_security_headers_present_on_error_responses(): void
    {
        $response = $this->postJson('/api/admin/login', []);
        
        $response->assertStatus(400);
        
        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertEquals('DENY', $response->headers->get('X-Frame-Options'));
    }

    // ===== CORS Tests =====

    public function test_cors_allows_stateful_domains(): void
    {
        $origin = 'http://localhost:3000';
        
        $response = $this->withHeaders(['Origin' => $origin])
            ->options('/api/public/recipient-roles');

        $response->assertStatus(200);
        $this->assertEquals($origin, $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('GET, POST', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('Content-Type', $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('Authorization', $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('Idempotency-Key', $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('X-XSRF-TOKEN', $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertEquals('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_cors_preflight_works_for_protected_endpoints(): void
    {
        $origin = 'http://localhost:8082';
        
        $response = $this->withHeaders(['Origin' => $origin])
            ->options('/api/reception/deliveries/pending');

        $response->assertStatus(200);
        $this->assertEquals($origin, $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cors_blocks_unknown_origins(): void
    {
        $origin = 'http://evil.com';
        
        $response = $this->withHeaders(['Origin' => $origin])
            ->options('/api/public/recipient-roles');

        // Should not allow unknown origin
        $this->assertNotEquals($origin, $response->headers->get('Access-Control-Allow-Origin'));
    }

    // ===== Idempotency Key Header Tests =====

    public function test_idempotency_key_header_accepted(): void
    {
        $idempotencyKey = 'test-key-' . uniqid();

        $response = $this->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson('/api/public/deliveries', [
                'fullName' => 'Test User',
            ]);

        $response->assertStatus(201);

        // Second request with same key should return same delivery
        $response2 = $this->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson('/api/public/deliveries', [
                'fullName' => 'Test User',
            ]);

        $response2->assertStatus(201);
        $this->assertEquals($response->json('trackingNumber'), $response2->json('trackingNumber'));
    }
}