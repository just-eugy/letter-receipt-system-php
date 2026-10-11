<?php

namespace Tests\Feature\Security;

use App\Events\DeliveryCreated;
use App\Events\DeliveryReceived;
use App\Models\LetterDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeEventTest extends TestCase
{
    use RefreshDatabase;

    protected User $receptionist;

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
    }

    public function test_delivery_created_event_broadcasts(): void
    {
        Event::fake();

        $delivery = LetterDelivery::factory()->create([
            'tracking_number' => 'REF-2026-TEST123',
            'delivery_person_name' => 'Test User',
            'delivery_person_phone' => '0200000000',
            'delivery_person_email' => 'test@example.com',
            'recipient_title' => 'HR Officer',
            'status' => 'DELIVERED',
            'delivered_at' => now(),
        ]);

        event(new \App\Events\DeliveryCreated($delivery));

        Event::assertDispatched(\App\Events\DeliveryCreated::class, function ($event) use ($delivery) {
            return $event->delivery->id === $delivery->id &&
                   $event->broadcastAs() === 'DELIVERY_CREATED';
        });
    }

    public function test_delivery_received_event_broadcasts(): void
    {
        Event::fake();

        $delivery = LetterDelivery::factory()->create([
            'tracking_number' => 'REF-2026-TEST123',
            'delivery_person_name' => 'Test User',
            'status' => 'RECEIVED',
            'delivered_at' => now()->subHour(),
            'received_at' => now(),
            'received_by_user_id' => $this->receptionist->id,
        ]);

        event(new \App\Events\DeliveryReceived($delivery));

        Event::assertDispatched(\App\Events\DeliveryReceived::class, function ($event) use ($delivery) {
            return $event->delivery->id === $delivery->id &&
                   $event->broadcastAs() === 'DELIVERY_STATUS_CHANGED';
        });
    }

    public function test_delivery_created_broadcasts_on_correct_channel(): void
    {
        $delivery = LetterDelivery::factory()->create([
            'tracking_number' => 'REF-2026-TEST123',
            'status' => 'DELIVERED',
            'delivered_at' => now(),
        ]);

        $event = new \App\Events\DeliveryCreated($delivery);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-deliveries', $channels[0]->name);
    }

    public function test_delivery_received_broadcasts_on_correct_channel(): void
    {
        $delivery = LetterDelivery::factory()->create([
            'tracking_number' => 'REF-2026-TEST123',
            'status' => 'RECEIVED',
            'delivered_at' => now()->subHour(),
            'received_at' => now(),
            'received_by_user_id' => $this->receptionist->id,
        ]);

        $event = new \App\Events\DeliveryReceived($delivery);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-deliveries', $channels[0]->name);
    }

    public function test_delivery_created_broadcast_payload_structure(): void
    {
        $delivery = LetterDelivery::factory()->create([
            'tracking_number' => 'REF-2026-TEST123',
            'delivery_person_name' => 'Test User',
            'delivery_person_phone' => '0200000000',
            'delivery_person_email' => 'test@example.com',
            'recipient_title' => 'HR Officer',
            'status' => 'DELIVERED',
            'delivered_at' => now(),
        ]);

        $event = new \App\Events\DeliveryCreated($delivery);
        $payload = $event->broadcastWith();

        $this->assertEquals('DELIVERY_CREATED', $payload['type']);
        $this->assertEquals($delivery->id, $payload['deliveryId']);
        $this->assertEquals('REF-2026-TEST123', $payload['delivery']['trackingNumber']);
        $this->assertEquals('Test User', $payload['delivery']['deliveryPersonName']);
        $this->assertEquals('HR Officer', $payload['delivery']['recipientTitle']);
        $this->assertEquals('DELIVERED', $payload['delivery']['status']);
        $this->assertEquals('New delivery received', $payload['message']);
    }

    public function test_delivery_received_broadcast_payload_structure(): void
    {
        $delivery = LetterDelivery::factory()->create([
            'tracking_number' => 'REF-2026-TEST123',
            'delivery_person_name' => 'Test User',
            'status' => 'RECEIVED',
            'delivered_at' => now()->subHour(),
            'received_at' => now(),
            'received_by_user_id' => $this->receptionist->id,
        ]);

        $event = new \App\Events\DeliveryReceived($delivery);
        $payload = $event->broadcastWith();

        $this->assertEquals('DELIVERY_STATUS_CHANGED', $payload['type']);
        $this->assertEquals($delivery->id, $payload['deliveryId']);
        $this->assertEquals('RECEIVED', $payload['status']);
        $this->assertEquals('REF-2026-TEST123', $payload['delivery']['trackingNumber']);
        $this->assertEquals('Test User', $payload['delivery']['deliveryPersonName']);
    }
}