<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryEvent extends Model
{
    use HasFactory;

    protected $table = 'delivery_events';
    public $timestamps = false;

    protected $fillable = [
        'delivery_id',
        'event_type',
        'actor_name',
        'remarks',
        'ip_address',
        'user_agent',
        'event_at',
    ];

    protected $casts = [
        'delivery_id' => 'integer',
        'event_type' => 'string',
        'event_at' => 'datetime',
    ];

    public const TYPE_DELIVERED = 'DELIVERED';
    public const TYPE_RECEIVED = 'RECEIVED';

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(LetterDelivery::class, 'delivery_id');
    }

    public function isDelivered(): bool
    {
        return $this->event_type === self::TYPE_DELIVERED;
    }

    public function isReceived(): bool
    {
        return $this->event_type === self::TYPE_RECEIVED;
    }
}