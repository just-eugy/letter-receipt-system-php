<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicDeliveryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => null,
            'trackingNumber' => $this->tracking_number,
            'status' => $this->status,
            'deliveryPersonName' => null,
            'deliveryPersonPhone' => null,
            'deliveryPersonEmail' => null,
            'recipientTitle' => $this->recipient_title,
            'deliveredAt' => $this->delivered_at?->format('Y-m-d\TH:i:s'),
            'receivedAt' => $this->received_at?->format('Y-m-d\TH:i:s'),
            'receivedBy' => null,
        ];
    }
}