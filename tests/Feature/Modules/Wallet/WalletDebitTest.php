<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Wallet;

use App\Models\User;
use App\Modules\Wallet\Actions\CreditWalletAction;
use App\Modules\Wallet\Actions\DebitWalletAction;
use App\Modules\Wallet\Exceptions\InsufficientBalanceException;
use App\Modules\Wallet\Models\WalletAccount;
use App\Modules\Wallet\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class WalletDebitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WalletAccount $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'telegram_id' => 987654321,
            'first_name' => 'WalletDebitTest',
        ]);

        $this->wallet = WalletAccount::create([
            'user_id' => $this->user->id,
            'available_amount_rial' => 500000, // 50,000 Toman
            'blocked_amount_rial' => 0,
            'version' => 1,
        ]);
    }

    // ──────────────────────────────────────────────
    // Sufficient Balance
    // ──────────────────────────────────────────────

    public function test_debit_succeeds_with_sufficient_balance(): void
    {
        $action = app(DebitWalletAction::class);
        $key = Str::uuid()->toString();

        $transaction = $action->execute(
            $this->wallet, 100000, 'Test debit', 'order', 1, $key
        );

        $this->wallet->refresh();

        $this->assertNotNull($transaction);
        $this->assertSame(400000, $this->wallet->available_amount_rial);
    }

    public function test_debit_balance_after_is_correct(): void
    {
        $action = app(DebitWalletAction::class);
        $key = Str::uuid()->toString();

        $transaction = $action->execute(
            $this->wallet, 150000, 'Test debit', 'order', 1, $key
        );

        $this->assertSame(350000, $transaction->balance_after_rial);

        $this->wallet->refresh();
        $this->assertSame(350000, $this->wallet->available_amount_rial);
    }

    public function test_debit_creates_correct_transaction_record(): void
    {
        $action = app(DebitWalletAction::class);
        $key = Str::uuid()->toString();

        $action->execute(
            $this->wallet, 200000, 'Order payment', 'order', 1, $key
        );

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $this->wallet->id,
            'type' => 'debit',
            'amount_rial' => 200000,
            'balance_after_rial' => 300000,
            'idempotency_key' => $key,
        ]);
    }

    // ──────────────────────────────────────────────
    // Insufficient Balance
    // ──────────────────────────────────────────────

    public function test_debit_fails_with_insufficient_balance(): void
    {
        $action = app(DebitWalletAction::class);
        $key = Str::uuid()->toString();

        $this->expectException(InsufficientBalanceException::class);

        $action->execute(
            $this->wallet, 999999, 'Large order', 'order', 1, $key
        );
    }

    public function test_insufficient_debit_does_not_create_transaction(): void
    {
        $action = app(DebitWalletAction::class);
        $key = Str::uuid()->toString();

        try {
            $action->execute(
                $this->wallet, 999999, 'Large order', 'order', 1, $key
            );
        } catch (InsufficientBalanceException) {
            // Expected
        }

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_insufficient_debit_does_not_create_negative_balance(): void
    {
        $action = app(DebitWalletAction::class);
        $key = Str::uuid()->toString();

        try {
            $action->execute(
                $this->wallet, 999999, 'Large order', 'order', 1, $key
            );
        } catch (InsufficientBalanceException) {
            // Expected
        }

        $this->wallet->refresh();
        $this->assertSame(500000, $this->wallet->available_amount_rial);
        $this->assertGreaterThanOrEqual(0, $this->wallet->available_amount_rial);
    }

    // ──────────────────────────────────────────────
    // Immutability
    // ──────────────────────────────────────────────

    public function test_wallet_transaction_update_throws_logic_exception(): void
    {
        $action = app(CreditWalletAction::class);
        $key = Str::uuid()->toString();

        $transaction = $action->execute(
            $this->wallet, 10000, 'Test', 'manual', 1, $key
        );

        $this->expectException(LogicException::class);
        $transaction->update(['amount_rial' => 99999]);
    }

    public function test_wallet_transaction_delete_throws_logic_exception(): void
    {
        $action = app(CreditWalletAction::class);
        $key = Str::uuid()->toString();

        $transaction = $action->execute(
            $this->wallet, 10000, 'Test', 'manual', 1, $key
        );

        $this->expectException(LogicException::class);
        $transaction->delete();
    }

    public function test_wallet_transaction_force_delete_throws_logic_exception(): void
    {
        $transaction = new WalletTransaction();

        $this->expectException(LogicException::class);
        $transaction->forceDelete();
    }
}
