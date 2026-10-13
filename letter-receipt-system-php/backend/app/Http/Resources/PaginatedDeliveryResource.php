<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class PaginatedDeliveryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator $this->resource */
        return [
            'content' => DeliveryResource::collection($this->resource->getCollection()),
            'page' => $this->resource->currentPage() - 1, // 0-based like Spring
            'size' => $this->resource->perPage(),
            'totalElements' => $this->resource->total(),
            'totalPages' => $this->resource->lastPage(),
            'first' => $this->resource->currentPage() === 1,
            'last' => $this->resource->currentPage() === $this->resource->lastPage(),
            'empty' => $this->resource->total() === 0,
        ];
    }
}