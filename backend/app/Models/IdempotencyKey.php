<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasFactory;

    protected $table = 'idempotency_keys';
    public $timestamps = false;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'idempotency_key',
        'tracking_number',
        'created_at',
        'expires_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public static function createFromRequest(string $key, string $trackingNumber): self
    {
        return self::create([
            'idempotency_key' => $key,
            'tracking_number' => $trackingNumber,
            'created_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
    }

    public static function findValid(string $key): ?self
    {
        return self::where('idempotency_key', $key)
            ->where('expires_at', '>', now())
            ->first();
    }

    public static function cleanupExpired(): int
    {
        return self::where('expires_at', '<=', now())->delete();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}