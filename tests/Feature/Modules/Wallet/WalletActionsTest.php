<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Wallet;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Modules\Wallet\Actions\CreditWalletAction;
use App\Modules\Wallet\Actions\DebitWalletAction;
use App\Modules\Wallet\Models\WalletAccount;
use App\Models\User;
use App\Modules\Wallet\Exceptions\InsufficientBalanceException;
use Illuminate\Support\Str;

class WalletActionsTest extends TestCase
{
    use RefreshDatabase;

    private WalletAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        
        $user = User::create([
            'telegram_id' => 123456789,
            'first_name' => 'Test',
        ]);
        
        $this->account = WalletAccount::create([
            'user_id' => $user->id,
            'available_amount_rial' => 10000,
            'blocked_amount_rial' => 0,
            'version' => 1,
        ]);
    }

    public function test_credit_wallet_action_successfully_increases_balance_and_creates_transaction(): void
    {
        $action = app(CreditWalletAction::class);
        $idempotencyKey = Str::uuid()->toString();

        $transaction = $action->execute(
            $this->account,
            5000,
            'Top up',
            'manual',
            1,
            $idempotencyKey
        );

        $this->account->refresh();
        
        $this->assertEquals(15000, $this->account->available_amount_rial);
        $this->assertEquals(2, $this->account->version);
        $this->assertDatabaseHas('wallet_transactions', [
            'id' => $transaction->id,
            'wallet_id' => $this->account->id,
            'type' => 'credit',
            'amount_rial' => 5000,
            'balance_after_rial' => 15000,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function test_debit_wallet_action_successfully_decreases_balance_and_creates_transaction(): void
    {
        $action = app(DebitWalletAction::class);
        $idempotencyKey = Str::uuid()->toString();

        $transaction = $action->execute(
            $this->account,
            3000,
            'Order payment',
            'order',
            1,
            $idempotencyKey
        );

        $this->account->refresh();
        
        $this->assertEquals(7000, $this->account->available_amount_rial);
        $this->assertEquals(2, $this->account->version);
        $this->assertDatabaseHas('wallet_transactions', [
            'id' => $transaction->id,
            'wallet_id' => $this->account->id,
            'type' => 'debit',
            'amount_rial' => 3000,
            'balance_after_rial' => 7000,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function test_debit_wallet_action_throws_exception_when_balance_is_insufficient(): void
    {
        $action = app(DebitWalletAction::class);
        $idempotencyKey = Str::uuid()->toString();

        $this->expectException(InsufficientBalanceException::class);

        $action->execute(
            $this->account,
            15000, // Account only has 10000
            'Large order',
            'order',
            1,
            $idempotencyKey
        );
    }

    public function test_credit_wallet_action_is_idempotent(): void
    {
        $action = app(CreditWalletAction::class);
        $idempotencyKey = Str::uuid()->toString();

        $transaction1 = $action->execute($this->account, 5000, 'Top up', 'manual', 1, $idempotencyKey);
        $transaction2 = $action->execute($this->account, 5000, 'Top up', 'manual', 1, $idempotencyKey);
        
        $this->account->refresh();
        
        $this->assertEquals($transaction1->id, $transaction2->id);
        $this->assertEquals(15000, $this->account->available_amount_rial);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_debit_wallet_action_is_idempotent(): void
    {
        $action = app(DebitWalletAction::class);
        $idempotencyKey = Str::uuid()->toString();

        $transaction1 = $action->execute($this->account, 3000, 'Order', 'order', 1, $idempotencyKey);
        $transaction2 = $action->execute($this->account, 3000, 'Order', 'order', 1, $idempotencyKey);
        
        $this->account->refresh();
        
        $this->assertEquals($transaction1->id, $transaction2->id);
        $this->assertEquals(7000, $this->account->available_amount_rial);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }
}
