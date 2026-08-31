<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationState extends Model
{
    protected $fillable = [
        'user_id',
        'handler',
        'step',
        'data_json',
        'expires_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'data_json' => 'array',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
