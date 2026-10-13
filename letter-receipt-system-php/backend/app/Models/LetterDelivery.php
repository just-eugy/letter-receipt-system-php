<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LetterDelivery extends Model
{
    use HasFactory;

    protected $table = 'letter_deliveries';

    protected $fillable = [
        'tracking_number',
        'delivery_person_name',
        'delivery_person_phone',
        'delivery_person_email',
        'recipient_title',
        'status',
        'delivered_at',
        'received_at',
        'received_by_user_id',
    ];

    protected $casts = [
        'version' => 'integer',
        'status' => 'string',
        'delivered_at' => 'datetime',
        'received_at' => 'datetime',
        'received_by_user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_RECEIVED = 'RECEIVED';

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeliveryEvent::class, 'delivery_id');
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    public function markAsReceived(User $user, ?string $remarks = null): void
    {
        $this->status = self::STATUS_RECEIVED;
        $this->received_at = now();
        $this->received_by_user_id = $user->id;
        $this->save();

        DeliveryEvent::create([
            'delivery_id' => $this->id,
            'event_type' => DeliveryEvent::TYPE_RECEIVED,
            'actor_name' => $user->display_name,
            'remarks' => $remarks ?? 'Physical letter verified and received',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'event_at' => now(),
        ]);
    }
}