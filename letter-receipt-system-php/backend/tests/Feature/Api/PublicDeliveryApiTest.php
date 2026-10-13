<?php

namespace Tests\Feature\Api;

use App\Models\LetterDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicDeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_recipient_roles_returns_canonical_list(): void
    {
        $response = $this->getJson('/api/public/recipient-roles');

        $response->assertStatus(200)
            ->assertJsonCount(15)
            ->assertJson([
                'HR Officer',
                'IT Officer',
                'Finance Manager',
                'Human Resource Manager',
                'Chief Executive Officer',
                'Chief Technology Officer',
                'Managing Director',
                'Administrative Manager',
                'Accountant',
                'Procurement Officer',
                'Internal Auditor',
                'Risk Manager',
                'Security Manager',
                'Receptionist',
                'Driver',
            ]);
    }

    public function test_create_delivery_with_valid_data(): void
    {
        $payload = [
            'fullName' => 'Kwame Mensah',
            'phone' => '0200000000',
            'email' => 'kwame@example.com',
            'recipient' => 'HR Officer',
        ];

        $response = $this->postJson('/api/public/deliveries', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'trackingNumber',
                'status',
                'deliveryPersonName',
                'deliveryPersonPhone',
                'deliveryPersonEmail',
                'recipientTitle',
                'deliveredAt',
                'receivedAt',
                'receivedBy',
            ])
            ->assertJson([
                'status' => 'DELIVERED',
                'deliveryPersonName' => 'Kwame Mensah',
                'deliveryPersonPhone' => '0200000000',
                'deliveryPersonEmail' => 'kwame@example.com',
                'recipientTitle' => 'HR Officer',
                'receivedAt' => null,
                'receivedBy' => null,
            ]);

        $this->assertDatabaseHas('letter_deliveries', [
            'delivery_person_name' => 'Kwame Mensah',
            'status' => 'DELIVERED',
        ]);
    }

    public function test_create_delivery_with_minimal_data(): void
    {
        $payload = [
            'fullName' => 'Test User',
        ];

        $response = $this->postJson('/api/public/deliveries', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'deliveryPersonName' => 'Test User',
                'deliveryPersonPhone' => null,
                'deliveryPersonEmail' => null,
                'recipientTitle' => null,
            ]);
    }

    public function test_create_delivery_requires_full_name(): void
    {
        $payload = [
            'phone' => '0200000000',
        ];

        $response = $this->postJson('/api/public/deliveries', $payload);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonPath('errors.fullName.0', 'Full name is required.');
    }

    public function test_create_delivery_validates_full_name_max_length(): void
    {
        $payload = [
            'fullName' => str_repeat('a', 161),
        ];

        $response = $this->postJson('/api/public/deliveries', $payload);

        $response->assertStatus(400)
            ->assertJsonPath('errors.fullName.0', 'Full name must not exceed 160 characters.');
    }

    public function test_create_delivery_validates_email_format(): void
    {
        $payload = [
            'fullName' => 'Test User',
            'email' => 'invalid-email',
        ];

        $response = $this->postJson('/api/public/deliveries', $payload);

        $response->assertStatus(400)
            ->assertJsonPath('errors.email.0', 'Email must be a valid email address.');
    }

    public function test_create_delivery_validates_email_max_length(): void
    {
        $payload = [
            'fullName' => 'Test User',
            'email' => str_repeat('a', 181) . '@example.com',
        ];

        $response = $this->postJson('/api/public/deliveries', $payload);

        $response->assertStatus(400)
            ->assertJsonPath('errors.email.0', 'Email must not exceed 180 characters.');
    }

    public function test_create_delivery_validates_phone_max_length(): void
    {
        $payload = [
            'fullName' => 'Test User',
            'phone' => str_repeat('0', 61),
        ];

        $response = $this->postJson('/api/public/deliveries', $payload);

        $response->assertStatus(400)
            ->assertJsonPath('errors.phone.0', 'Phone must not exceed 60 characters.');
    }

    public function test_create_delivery_validates_recipient_max_length(): void
    {
        $payload = [
            'fullName' => 'Test User',
            'recipient' => str_repeat('a', 161),
        ];

        $response = $this->postJson('/api/public/deliveries', $payload);

        $response->assertStatus(400)
            ->assertJsonPath('errors.recipient.0', 'Recipient must not exceed 160 characters.');
    }

    public function test_create_delivery_idempotency_key_prevents_duplicates(): void
    {
        $payload = [
            'fullName' => 'Kwame Mensah',
            'phone' => '0200000000',
            'email' => 'kwame@example.com',
            'recipient' => 'HR Officer',
        ];
        $idempotencyKey = 'test-idempotency-key-' . uniqid();

        // First request
        $response1 = $this->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson('/api/public/deliveries', $payload);

        $response1->assertStatus(201);
        $trackingNumber1 = $response1->json('trackingNumber');

        // Second request with same idempotency key
        $response2 = $this->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson('/api/public/deliveries', $payload);

        $response2->assertStatus(201);
        $trackingNumber2 = $response2->json('trackingNumber');

        // Should return the same tracking number
        $this->assertEquals($trackingNumber1, $trackingNumber2);

        // Should only have one delivery in database
        $this->assertDatabaseCount('letter_deliveries', 1);
    }

    public function test_track_delivery_returns_public_view(): void
    {
        $delivery = LetterDelivery::factory()->create([
            'tracking_number' => 'REF-2026-TEST123',
            'delivery_person_name' => 'Test User',
            'delivery_person_phone' => '0200000000',
            'delivery_person_email' => 'test@example.com',
            'recipient_title' => 'HR Officer',
            'status' => 'DELIVERED',
            'delivered_at' => now()->subHour(),
        ]);

        $response = $this->getJson('/api/public/deliveries/REF-2026-TEST123');

        $response->assertStatus(200)
            ->assertJson([
                'id' => null,
                'trackingNumber' => 'REF-2026-TEST123',
                'status' => 'DELIVERED',
                'deliveryPersonName' => null,
                'deliveryPersonPhone' => null,
                'deliveryPersonEmail' => null,
                'recipientTitle' => 'HR Officer',
                'receivedAt' => null,
                'receivedBy' => null,
            ]);
    }

    public function test_track_delivery_not_found(): void
    {
        $response = $this->getJson('/api/public/deliveries/NONEXISTENT');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Delivery not found.');
    }
}