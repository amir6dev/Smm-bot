<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Models\WalletAccount;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Modules\Wallet\Enums\TransactionType;
use App\Modules\Wallet\Exceptions\InsufficientBalanceException;
use Illuminate\Support\Facades\DB;

class DebitWalletAction
{
    public function execute(
        WalletAccount $account,
        int $amountRial,
        string $reason,
        string $referenceType,
        int $referenceId,
        string $idempotencyKey,
        string $actorType = 'system',
        ?int $actorId = null
    ): WalletTransaction {
        return DB::transaction(function () use (
            $account, $amountRial, $reason, $referenceType, $referenceId, $idempotencyKey, $actorType, $actorId
        ) {
            $lockedAccount = WalletAccount::where('id', $account->id)->lockForUpdate()->first();

            $existingTransaction = WalletTransaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existingTransaction) {
                return $existingTransaction;
            }

            if ($lockedAccount->available_amount_rial < $amountRial) {
                throw new InsufficientBalanceException("Insufficient balance for this operation.");
            }

            $newBalance = $lockedAccount->available_amount_rial - $amountRial;

            $transaction = WalletTransaction::create([
                'wallet_id' => $lockedAccount->id,
                'type' => TransactionType::DEBIT->value,
                'amount_rial' => $amountRial,
                'balance_after_rial' => $newBalance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
            ]);

            $lockedAccount->available_amount_rial = $newBalance;
            $lockedAccount->version += 1;
            $lockedAccount->save();

            return $transaction;
        });
    }
}
