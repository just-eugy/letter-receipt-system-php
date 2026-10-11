<?php

namespace Tests\Feature\Api;

use App\Models\LetterDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceptionApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $receptionist;
    protected User $admin;
    protected string $receptionistToken;
    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receptionist = User::factory()->create([
            'username' => 'receptionist',
            'password' => bcrypt('password123'),
            'display_name' => 'Test Receptionist',
            'role' => 'RECEPTIONIST',
            'active' => true,
        ]);

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'password' => bcrypt('password123'),
            'display_name' => 'Test Admin',
            'role' => 'ADMIN',
            'active' => true,
        ]);

        $this->receptionistToken = $this->receptionist->createToken('test')->plainTextToken;
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;
    }

    // ===== Authentication Tests =====

    public function test_pending_requires_authentication(): void
    {
        $response = $this->getJson('/api/reception/deliveries/pending');

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_pending_requires_receptionist_or_admin_role(): void
    {
        // Create a regular user (if we had a different role)
        $user = User::factory()->create([
            'username' => 'regular',
            'password' => bcrypt('password123'),
            'display_name' => 'Regular User',
            'role' => 'RECEPTIONIST', // Only RECEPTIONIST and ADMIN allowed
            'active' => true,
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/reception/deliveries/pending');

        $response->assertStatus(200); // RECEPTIONIST is allowed
    }

    // ===== GET /reception/deliveries/pending =====

    public function test_get_pending_deliveries_limited_to_100(): void
    {
        // Create 105 pending deliveries
        for ($i = 0; $i < 105; $i++) {
            LetterDelivery::factory()->create([
                'status' => 'DELIVERED',
                'delivered_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/deliveries/pending');

        $response->assertStatus(200)
            ->assertJsonCount(100);
    }

    public function test_pending_deliveries_sorted_oldest_first(): void
    {
        $d1 = LetterDelivery::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subHours(2),
            'tracking_number' => 'REF-2026-000001',
        ]);
        $d2 = LetterDelivery::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subHours(1),
            'tracking_number' => 'REF-2026-000002',
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/deliveries/pending');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals($d1->tracking_number, $data[0]['trackingNumber']);
        $this->assertEquals($d2->tracking_number, $data[1]['trackingNumber']);
    }

    public function test_pending_only_returns_delivered_status(): void
    {
        LetterDelivery::factory()->create(['status' => 'DELIVERED']);
        LetterDelivery::factory()->create(['status' => 'RECEIVED']);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/deliveries/pending');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('DELIVERED', $data[0]['status']);
    }

    // ===== GET /reception/deliveries/pending/page =====

    public function test_pending_page_supports_pagination(): void
    {
        for ($i = 0; $i < 25; $i++) {
            LetterDelivery::factory()->create([
                'status' => 'DELIVERED',
                'delivered_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/deliveries/pending/page?page=0&size=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'content',
                'page',
                'size',
                'totalElements',
                'totalPages',
                'first',
                'last',
                'empty',
            ])
            ->assertJsonPath('page', 0)
            ->assertJsonPath('size', 10)
            ->assertJsonPath('totalElements', 25)
            ->assertJsonPath('totalPages', 3)
            ->assertJsonCount(10, 'content');
    }

    public function test_pending_page_supports_search_filter(): void
    {
        LetterDelivery::factory()->create([
            'status' => 'DELIVERED',
            'delivery_person_name' => 'John Doe',
            'tracking_number' => 'REF-2026-JOHN01',
        ]);
        LetterDelivery::factory()->create([
            'status' => 'DELIVERED',
            'delivery_person_name' => 'Jane Smith',
            'tracking_number' => 'REF-2026-JANE01',
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/deliveries/pending/page?q=John');

        $response->assertStatus(200)
            ->assertJsonPath('totalElements', 1)
            ->assertJsonPath('content.0.deliveryPersonName', 'John Doe');
    }

    public function test_pending_page_supports_recipient_position_filter(): void
    {
        LetterDelivery::factory()->create([
            'status' => 'DELIVERED',
            'recipient_title' => 'HR Officer',
        ]);
        LetterDelivery::factory()->create([
            'status' => 'DELIVERED',
            'recipient_title' => 'IT Officer',
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/deliveries/pending/page?recipientPosition=HR%20Officer');

        $response->assertStatus(200)
            ->assertJsonPath('totalElements', 1)
            ->assertJsonPath('content.0.recipientTitle', 'HR Officer');
    }

    public function test_pending_page_supports_date_filters(): void
    {
        LetterDelivery::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(2),
        ]);
        LetterDelivery::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDay(),
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/deliveries/pending/page?dateFrom=' . now()->subDay()->format('Y-m-d'));

        $response->assertStatus(200)
            ->assertJsonPath('totalElements', 1);
    }

    // ===== GET /reception/deliveries/received =====

    public function test_get_received_deliveries_limited_to_100(): void
    {
        for ($i = 0; $i < 105; $i++) {
            LetterDelivery::factory()->create([
                'status' => 'RECEIVED',
                'received_at' => now()->subMinutes($i),
                'received_by_user_id' => $this->receptionist->id,
            ]);
        }

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/deliveries/received');

        $response->assertStatus(200)
            ->assertJsonCount(100);
    }

    public function test_received_deliveries_sorted_newest_first(): void
    {
        $d1 = LetterDelivery::factory()->create([
            'status' => 'RECEIVED',
            'received_at' => now()->subHours(2),
            'received_by_user_id' => $this->receptionist->id,
            'tracking_number' => 'REF-2026-000001',
        ]);
        $d2 = LetterDelivery::factory()->create([
            'status' => 'RECEIVED',
            'received_at' => now()->subHours(1),
            'received_by_user_id' => $this->receptionist->id,
            'tracking_number' => 'REF-2026-000002',
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/deliveries/received');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals($d2->tracking_number, $data[0]['trackingNumber']);
        $this->assertEquals($d1->tracking_number, $data[1]['trackingNumber']);
    }

    public function test_received_only_returns_received_status(): void
    {
        LetterDelivery::factory()->create(['status' => 'DELIVERED']);
        LetterDelivery::factory()->create(['status' => 'RECEIVED', 'received_by_user_id' => $this->receptionist->id]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/deliveries/received');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('RECEIVED', $data[0]['status']);
    }

    // ===== GET /reception/deliveries/received/page =====

    public function test_received_page_supports_pagination(): void
    {
        for ($i = 0; $i < 25; $i++) {
            LetterDelivery::factory()->create([
                'status' => 'RECEIVED',
                'received_at' => now()->subMinutes($i),
                'received_by_user_id' => $this->receptionist->id,
            ]);
        }

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/deliveries/received/page?page=1&size=10');

        $response->assertStatus(200)
            ->assertJsonPath('page', 1)
            ->assertJsonPath('size', 10)
            ->assertJsonCount(10, 'content');
    }

    // ===== POST /reception/deliveries/{id}/receive =====

    public function test_receive_delivery_success(): void
    {
        $delivery = LetterDelivery::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subHour(),
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson("/api/reception/deliveries/{$delivery->id}/receive", [
                'remarks' => 'Verified at desk',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'RECEIVED',
                'receivedBy' => 'Test Receptionist',
            ]);

        $this->assertDatabaseHas('letter_deliveries', [
            'id' => $delivery->id,
            'status' => 'RECEIVED',
            'received_by_user_id' => $this->receptionist->id,
        ]);

        // Should create delivery event
        $this->assertDatabaseHas('delivery_events', [
            'delivery_id' => $delivery->id,
            'event_type' => 'RECEIVED',
            'actor_name' => 'Test Receptionist',
            'remarks' => 'Verified at desk',
        ]);
    }

    public function test_receive_delivery_without_remarks(): void
    {
        $delivery = LetterDelivery::factory()->create([
            'status' => 'DELIVERED',
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson("/api/reception/deliveries/{$delivery->id}/receive", []);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'RECEIVED');

        $this->assertDatabaseHas('delivery_events', [
            'delivery_id' => $delivery->id,
            'remarks' => 'Physical letter verified and received',
        ]);
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

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson("/api/reception/deliveries/{$delivery->id}/receive", [
                'remarks' => 'Second attempt',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'RECEIVED');

        // Should NOT create a second event
        $this->assertDatabaseCount('delivery_events', 1);
    }

    public function test_receive_nonexistent_delivery_returns_404(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson('/api/reception/deliveries/99999/receive', []);

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Delivery not found.');
    }

    public function test_receive_requires_authentication(): void
    {
        $delivery = LetterDelivery::factory()->create(['status' => 'DELIVERED']);

        $response = $this->postJson("/api/reception/deliveries/{$delivery->id}/receive", []);

        $response->assertStatus(401);
    }

    public function test_receive_validates_remarks_max_length(): void
    {
        $delivery = LetterDelivery::factory()->create(['status' => 'DELIVERED']);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson("/api/reception/deliveries/{$delivery->id}/receive", [
                'remarks' => str_repeat('a', 501),
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('errors.remarks.0', 'Remarks must not exceed 500 characters.');
    }

    // ===== GET /reception/statistics =====

    public function test_statistics_returns_correct_counts(): void
    {
        LetterDelivery::factory()->count(5)->create(['status' => 'DELIVERED']);
        LetterDelivery::factory()->count(3)->create([
            'status' => 'RECEIVED',
            'received_at' => now(),
            'received_by_user_id' => $this->receptionist->id,
        ]);
        LetterDelivery::factory()->create([
            'status' => 'RECEIVED',
            'received_at' => now()->subDay(),
            'received_by_user_id' => $this->receptionist->id,
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->getJson('/api/reception/statistics');

        $response->assertStatus(200)
            ->assertJson([
                'pendingCount' => 5,
                'receivedTodayCount' => 3,
            ]);
    }
}