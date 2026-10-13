<?php

namespace App\Events;

use App\Models\LetterDelivery;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeliveryReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LetterDelivery $delivery
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('deliveries'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'DELIVERY_STATUS_CHANGED';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'DELIVERY_STATUS_CHANGED',
            'deliveryId' => $this->delivery->id,
            'status' => $this->delivery->status,
            'delivery' => [
                'id' => $this->delivery->id,
                'trackingNumber' => $this->delivery->tracking_number,
                'deliveryPersonName' => $this->delivery->delivery_person_name,
                'deliveryPersonPhone' => $this->delivery->delivery_person_phone,
                'deliveryPersonEmail' => $this->delivery->delivery_person_email,
                'recipientTitle' => $this->delivery->recipient_title,
                'status' => $this->delivery->status,
                'deliveredAt' => $this->delivery->delivered_at?->format('Y-m-d\TH:i:s'),
                'receivedAt' => $this->delivery->received_at?->format('Y-m-d\TH:i:s'),
            ],
        ];
    }
}