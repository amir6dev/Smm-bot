<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Service;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\InvalidStatusTransitionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'service_id',
        'order_code',
        'status',
        'quantity',
        'amount_rial',
        'target_data_json',
        'snapshot_json',
        'action_token',
        'action_token_expires_at',
        'provider_order_id',
        'provider_status',
        'remains',
        'start_count',
        'submitted_at',
        'completed_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'service_id' => 'integer',
        'quantity' => 'integer',
        'amount_rial' => 'integer',
        'target_data_json' => 'array',
        'snapshot_json' => 'array',
        'remains' => 'integer',
        'start_count' => 'integer',
        'status' => OrderStatus::class,
        'action_token_expires_at' => 'datetime',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function orderEvents(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('created_at');
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    // ──────────────────────────────────────────────
    // Status Transition
    // ──────────────────────────────────────────────

    /**
     * Transition the order to a new status with validation.
     *
     * @throws InvalidStatusTransitionException
     */
    public function transitionTo(OrderStatus $newStatus, string $source = 'system', ?array $payload = null, ?int $actorId = null): self
    {
        $currentStatus = $this->status;

        if (!$currentStatus->canTransitionTo($newStatus)) {
            throw new InvalidStatusTransitionException($currentStatus->value, $newStatus->value);
        }

        $this->status = $newStatus;

        if ($newStatus === OrderStatus::COMPLETED) {
            $this->completed_at = now();
        }

        if ($newStatus === OrderStatus::SUBMITTED) {
            $this->submitted_at = now();
        }

        $this->save();

        // Record the transition event (append-only)
        OrderEvent::create([
            'order_id' => $this->id,
            'status_from' => $currentStatus->value,
            'status_to' => $newStatus->value,
            'source' => $source,
            'payload_json' => $payload,
            'actor_id' => $actorId,
        ]);

        return $this;
    }

    /**
     * Get the display amount in Toman (integer only).
     */
    public function amountToman(): int
    {
        return intdiv($this->amount_rial, 10);
    }
}
