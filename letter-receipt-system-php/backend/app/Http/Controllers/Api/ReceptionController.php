<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReceiveDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Http\Resources\PaginatedDeliveryResource;
use App\Models\LetterDelivery;
use App\Services\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReceptionController extends Controller
{
    public function __construct(
        private readonly DeliveryService $deliveryService
    ) {}

    /**
     * Get pending deliveries (limited to 100).
     */
    public function pending(): JsonResponse
    {
        $deliveries = $this->deliveryService->getPendingLimited(100);

        return response()->json(DeliveryResource::collection($deliveries));
    }

    /**
     * Get pending deliveries (paginated with filters).
     */
    public function pendingPage(Request $request): JsonResponse
    {
        $filters = $this->extractFilters($request);
        $page = max(0, (int) $request->get('page', 0));
        $size = min(100, max(1, (int) $request->get('size', 10)));

        $paginated = $this->deliveryService->getPendingPaginated($filters, $page, $size);

        return response()->json(new PaginatedDeliveryResource($paginated));
    }

    /**
     * Get received deliveries (limited to 100).
     */
    public function received(): JsonResponse
    {
        $deliveries = $this->deliveryService->getReceivedLimited(100);

        return response()->json(DeliveryResource::collection($deliveries));
    }

    /**
     * Get received deliveries (paginated with filters).
     */
    public function receivedPage(Request $request): JsonResponse
    {
        $filters = $this->extractFilters($request);
        $page = max(0, (int) $request->get('page', 0));
        $size = min(100, max(1, (int) $request->get('size', 10)));

        $paginated = $this->deliveryService->getReceivedPaginated($filters, $page, $size);

        return response()->json(new PaginatedDeliveryResource($paginated));
    }

    /**
     * Receive/confirm a delivery.
     */
    public function receive(ReceiveDeliveryRequest $request, int $id): JsonResponse
    {
        $delivery = LetterDelivery::find($id);

        if (!$delivery) {
            return response()->json([
                'timestamp' => now()->toISOString(),
                'message' => 'Delivery not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        // Authorization: user must be authenticated (handled by middleware)
        $user = $request->user();

        try {
            $delivery = $this->deliveryService->receive(
                $delivery,
                $user,
                $request->validated('remarks')
            );

            return response()->json(new DeliveryResource($delivery));
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle optimistic lock failure
            if ($e->getCode() === 'HY000' && str_contains($e->getMessage(), 'lock')) {
                return response()->json([
                    'timestamp' => now()->toISOString(),
                    'message' => 'This letter was updated by another user. Refresh and try again.',
                ], Response::HTTP_CONFLICT);
            }
            throw $e;
        }
    }

    /**
     * Get dashboard statistics.
     */
    public function statistics(): JsonResponse
    {
        $stats = $this->deliveryService->getStatistics();

        return response()->json($stats);
    }

    /**
     * Extract filter parameters from request.
     */
    private function extractFilters(Request $request): array
    {
        return array_filter([
            'q' => $request->get('q'),
            'dateFrom' => $request->get('dateFrom'),
            'dateTo' => $request->get('dateTo'),
            'recipientPosition' => $request->get('recipientPosition'),
            'status' => $request->get('status'),
        ], fn($v) => $v !== null && $v !== '');
    }
}