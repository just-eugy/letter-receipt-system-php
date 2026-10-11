<?php

namespace App\Services;

use App\Models\DeliveryEvent;
use App\Models\IdempotencyKey;
use App\Models\LetterDelivery;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DeliveryService
{
    // Alphabet for tracking number generation (excludes confusing characters)
    private const TRACKING_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const TRACKING_ATTEMPTS = 8;
    private const IDEMPOTENCY_TTL_HOURS = 24;

    /**
     * Create a new letter delivery.
     */
    public function create(array $data, ?string $idempotencyKey = null): LetterDelivery
    {
        // Check idempotency key first
        if ($idempotencyKey) {
            $existing = IdempotencyKey::findValid($idempotencyKey);
            if ($existing) {
                // Return the existing delivery
                return LetterDelivery::where('tracking_number', $existing->tracking_number)
                    ->with('receivedBy')
                    ->firstOrFail();
            }
        }

        return DB::transaction(function () use ($data, $idempotencyKey) {
            // Generate unique tracking number
            $trackingNumber = $this->generateTrackingNumber();

            // Create delivery
            $delivery = LetterDelivery::create([
                'tracking_number' => $trackingNumber,
                'delivery_person_name' => $data['fullName'],
                'delivery_person_phone' => $data['phone'] ?? null,
                'delivery_person_email' => $data['email'] ?? null,
                'recipient_title' => $data['recipient'] ?? null,
                'status' => LetterDelivery::STATUS_DELIVERED,
                'delivered_at' => now(),
                'version' => 0,
            ]);

            // Create audit event
            DeliveryEvent::create([
                'delivery_id' => $delivery->id,
                'event_type' => DeliveryEvent::TYPE_DELIVERED,
                'actor_name' => $data['fullName'],
                'remarks' => 'Letter submitted for receipt confirmation',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'event_at' => now(),
            ]);

            // Store idempotency key if provided
            if ($idempotencyKey) {
                IdempotencyKey::createFromRequest($idempotencyKey, $trackingNumber);
            }

            return $delivery->load('receivedBy');
        });
    }

    /**
     * Receive/confirm a delivery.
     */
    public function receive(LetterDelivery $delivery, User $user, ?string $remarks = null): LetterDelivery
    {
        // Idempotent: if already received, return current state
        if ($delivery->isReceived()) {
            return $delivery->fresh()->load('receivedBy');
        }

        // Use pessimistic locking to prevent race conditions
        $delivery = LetterDelivery::where('id', $delivery->id)
            ->lockForUpdate()
            ->firstOrFail();

        // Double-check after lock
        if ($delivery->isReceived()) {
            return $delivery->fresh()->load('receivedBy');
        }

        return DB::transaction(function () use ($delivery, $user, $remarks) {
            $delivery->status = LetterDelivery::STATUS_RECEIVED;
            $delivery->received_at = now();
            $delivery->received_by_user_id = $user->id;
            $delivery->version++; // Optimistic lock increment
            $delivery->save();

            // Create audit event
            DeliveryEvent::create([
                'delivery_id' => $delivery->id,
                'event_type' => DeliveryEvent::TYPE_RECEIVED,
                'actor_name' => $user->display_name,
                'remarks' => $remarks ?? 'Physical letter verified and received',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'event_at' => now(),
            ]);

            return $delivery->fresh()->load('receivedBy');
        });
    }

    /**
     * Get pending deliveries (paginated).
     */
    public function getPendingPaginated(array $filters = [], int $page = 0, int $size = 10)
    {
        $query = LetterDelivery::query()
            ->where('status', LetterDelivery::STATUS_DELIVERED)
            ->with('receivedBy')
            ->orderBy('delivered_at', 'asc');

        $this->applyFilters($query, $filters);

        // Convert 0-based page to 1-based for Laravel
        return $query->paginate($size, ['*'], 'page', $page + 1);
    }

    /**
     * Get received deliveries (paginated).
     */
    public function getReceivedPaginated(array $filters = [], int $page = 0, int $size = 10)
    {
        $query = LetterDelivery::query()
            ->where('status', LetterDelivery::STATUS_RECEIVED)
            ->with('receivedBy')
            ->orderBy('received_at', 'desc');

        $this->applyFilters($query, $filters);

        return $query->paginate($size, ['*'], 'page', $page + 1);
    }

    /**
     * Get pending deliveries (limited, for dashboard).
     */
    public function getPendingLimited(int $limit = 100)
    {
        return LetterDelivery::query()
            ->where('status', LetterDelivery::STATUS_DELIVERED)
            ->with('receivedBy')
            ->orderBy('delivered_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get received deliveries (limited, for dashboard).
     */
    public function getReceivedLimited(int $limit = 100)
    {
        return LetterDelivery::query()
            ->where('status', LetterDelivery::STATUS_RECEIVED)
            ->with('receivedBy')
            ->orderBy('received_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get dashboard statistics.
     */
    public function getStatistics(): array
    {
        $pendingCount = LetterDelivery::where('status', LetterDelivery::STATUS_DELIVERED)->count();
        $receivedTodayCount = LetterDelivery::where('status', LetterDelivery::STATUS_RECEIVED)
            ->whereDate('received_at', today())
            ->count();

        return [
            'pendingCount' => $pendingCount,
            'receivedTodayCount' => $receivedTodayCount,
        ];
    }

    /**
     * Generate report with filters.
     */
    public function generateReport(array $filters = []): array
    {
        $query = LetterDelivery::query()->with('receivedBy');

        $this->applyFilters($query, $filters);

        $totalRecords = $query->count();
        $records = $query->orderBy('delivered_at', 'desc')->limit(50000)->get();

        return [
            'records' => $records,
            'totalRecords' => $totalRecords,
            'filters' => $filters,
            'generatedAt' => now(),
        ];
    }

    /**
     * Get all deliveries for CSV/Excel export.
     */
    public function getExportRecords(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = LetterDelivery::query()->with('receivedBy');
        $this->applyFilters($query, $filters);
        return $query->orderBy('delivered_at', 'desc')->get();
    }

    /**
     * Apply common filters to query.
     */
    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['q'])) {
            $search = '%' . $filters['q'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('tracking_number', 'LIKE', $search)
                    ->orWhere('delivery_person_name', 'LIKE', $search)
                    ->orWhere('delivery_person_phone', 'LIKE', $search)
                    ->orWhere('delivery_person_email', 'LIKE', $search)
                    ->orWhere('recipient_title', 'LIKE', $search);
            });
        }

        if (!empty($filters['recipientPosition'])) {
            $query->where('recipient_title', $filters['recipientPosition']);
        }

        if (!empty($filters['dateFrom'])) {
            $query->where('delivered_at', '>=', Carbon::parse($filters['dateFrom'])->startOfDay());
        }

        if (!empty($filters['dateTo'])) {
            $query->where('delivered_at', '<=', Carbon::parse($filters['dateTo'])->endOfDay());
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
    }

    /**
     * Generate unique tracking number: REF-YYYY-XXXXXX
     */
    private function generateTrackingNumber(): string
    {
        $year = now()->format('Y');
        $alphabet = self::TRACKING_ALPHABET;

        for ($attempt = 0; $attempt < self::TRACKING_ATTEMPTS; $attempt++) {
            $token = 'REF-' . $year . '-';
            for ($i = 0; $i < 6; $i++) {
                $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            if (!LetterDelivery::where('tracking_number', $token)->exists()) {
                return $token;
            }
        }

        throw new \Exception('Could not generate unique tracking number after ' . self::TRACKING_ATTEMPTS . ' attempts');
    }
}