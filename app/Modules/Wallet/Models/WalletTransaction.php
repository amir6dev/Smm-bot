<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Wallet\Enums\TransactionType;
use App\Modules\Shared\Enums\ActorType;
use LogicException;

class WalletTransaction extends Model
{
    protected $guarded = ['id'];
    const UPDATED_AT = null;

    protected $casts = [
        'amount_rial' => 'integer',
        'balance_after_rial' => 'integer',
        'type' => TransactionType::class,
        'actor_type' => ActorType::class,
        'wallet_id' => 'integer',
        'reference_id' => 'integer',
        'actor_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function update(array $attributes = [], array $options = [])
    {
        throw new LogicException("WalletTransactions are strictly immutable and cannot be updated.");
    }

    public function delete()
    {
        throw new LogicException("WalletTransactions are strictly immutable and cannot be deleted.");
    }

    public static function destroy($ids)
    {
        throw new LogicException("WalletTransactions are strictly immutable and cannot be deleted.");
    }

    public function forceDelete()
    {
        throw new LogicException("WalletTransactions are strictly immutable and cannot be deleted.");
    }
}
