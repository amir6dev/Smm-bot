<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Wallet;

use Tests\TestCase;
use App\Modules\Wallet\Models\WalletTransaction;
use LogicException;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WalletTransactionImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_transaction_cannot_be_updated(): void
    {
        $transaction = new WalletTransaction();
        
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('WalletTransactions are strictly immutable and cannot be updated.');
        
        $transaction->update(['amount_rial' => 1000]);
    }

    public function test_wallet_transaction_cannot_be_deleted(): void
    {
        $transaction = new WalletTransaction();
        
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('WalletTransactions are strictly immutable and cannot be deleted.');
        
        $transaction->delete();
    }
    
    public function test_wallet_transaction_cannot_be_destroyed(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('WalletTransactions are strictly immutable and cannot be deleted.');
        
        WalletTransaction::destroy(1);
    }
}
