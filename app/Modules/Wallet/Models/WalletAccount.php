<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalletAccount extends Model
{
    protected $fillable = [
        'user_id',
        'available_amount_rial',
        'blocked_amount_rial',
        'version',
    ];

    protected $casts = [
        'available_amount_rial' => 'integer',
        'blocked_amount_rial' => 'integer',
        'version' => 'integer',
        'user_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id');
    }
}
