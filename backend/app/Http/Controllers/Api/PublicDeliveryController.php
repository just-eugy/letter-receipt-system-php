<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Http\Resources\PublicDeliveryResource;
use App\Models\LetterDelivery;
use App\Services\DeliveryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PublicDeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryService $deliveryService
    ) {}

    /**
     * Get canonical recipient roles.
     */
    public function recipientRoles(): JsonResponse
    {
        $roles = [
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
        ];

        return response()->json($roles);
    }

    /**
     * Create a new delivery.
     */
    public function create(CreateDeliveryRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        $delivery = $this->deliveryService->create(
            $request->validated(),
            $idempotencyKey
        );

        return response()->json(
            new DeliveryResource($delivery),
            Response::HTTP_CREATED
        );
    }

    /**
     * Track delivery by tracking number (public view).
     */
    public function track(string $tracking): JsonResponse
    {
        $delivery = LetterDelivery::where('tracking_number', $tracking)
            ->first();

        if (!$delivery) {
            return response()->json([
                'timestamp' => now()->toISOString(),
                'message' => 'Delivery not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json(new PublicDeliveryResource($delivery));
    }
}