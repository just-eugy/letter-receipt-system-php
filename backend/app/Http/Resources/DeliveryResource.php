<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trackingNumber' => $this->tracking_number,
            'status' => $this->status,
            'deliveryPersonName' => $this->delivery_person_name,
            'deliveryPersonPhone' => $this->delivery_person_phone,
            'deliveryPersonEmail' => $this->delivery_person_email,
            'recipientTitle' => $this->recipient_title,
            'deliveredAt' => $this->delivered_at?->format('Y-m-d\TH:i:s'),
            'receivedAt' => $this->received_at?->format('Y-m-d\TH:i:s'),
            'receivedBy' => $this->whenLoaded('receivedBy', function () {
                return $this->receivedBy->display_name;
            }),
        ];
    }
}