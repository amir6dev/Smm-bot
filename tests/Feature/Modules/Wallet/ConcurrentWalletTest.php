<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Wallet;

use App\Models\User;
use App\Modules\Wallet\Actions\DebitWalletAction;
use App\Modules\Wallet\Exceptions\InsufficientBalanceException;
use App\Modules\Wallet\Models\WalletAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests concurrent wallet debit attempts to verify that row-level locking
 * (lockForUpdate) prevents double-spending.
 *
 * Note: True concurrency testing requires a real database connection that supports
 * row-level locking (MySQL/PostgreSQL). With SQLite (RefreshDatabase), the test
 * simulates sequential competing debits to verify the balance check logic.
 */
class ConcurrentWalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_debits_only_one_succeeds_when_balance_covers_one(): void
    {
        $user = User::create([
            'telegram_id' => 111222333,
            'first_name' => 'ConcurrencyTest',
        ]);

        $wallet = WalletAccount::create([
            'user_id' => $user->id,
            'available_amount_rial' => 100000, // Enough for only one 100000 debit
            'blocked_amount_rial' => 0,
            'version' => 1,
        ]);

        $action = app(DebitWalletAction::class);

        $key1 = 'concurrent-debit-1-' . Str::uuid()->toString();
        $key2 = 'concurrent-debit-2-' . Str::uuid()->toString();

        $successCount = 0;
        $failureCount = 0;

        // First debit — should succeed
        try {
            $action->execute(
                $wallet, 100000, 'Order A', 'order', 1, $key1
            );
            $successCount++;
        } catch (InsufficientBalanceException) {
            $failureCount++;
        }

        // Second debit — should fail (balance now 0)
        try {
            $action->execute(
                $wallet, 100000, 'Order B', 'order', 2, $key2
            );
            $successCount++;
        } catch (InsufficientBalanceException) {
            $failureCount++;
        }

        $this->assertSame(1, $successCount, 'Exactly one debit should succeed.');
        $this->assertSame(1, $failureCount, 'Exactly one debit should fail.');

        $wallet->refresh();
        $this->assertSame(0, $wallet->available_amount_rial);
        $this->assertGreaterThanOrEqual(0, $wallet->available_amount_rial);

        // Only one wallet transaction should exist
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_two_debits_both_succeed_when_balance_covers_both(): void
    {
        $user = User::create([
            'telegram_id' => 222333444,
            'first_name' => 'BothSucceed',
        ]);

        $wallet = WalletAccount::create([
            'user_id' => $user->id,
            'available_amount_rial' => 200000, // Enough for two 100000 debits
            'blocked_amount_rial' => 0,
            'version' => 1,
        ]);

        $action = app(DebitWalletAction::class);

        $key1 = 'both-debit-1-' . Str::uuid()->toString();
        $key2 = 'both-debit-2-' . Str::uuid()->toString();

        $action->execute($wallet, 100000, 'Order A', 'order', 1, $key1);
        $action->execute($wallet, 100000, 'Order B', 'order', 2, $key2);

        $wallet->refresh();
        $this->assertSame(0, $wallet->available_amount_rial);
        $this->assertDatabaseCount('wallet_transactions', 2);
    }

    public function test_balance_never_goes_negative(): void
    {
        $user = User::create([
            'telegram_id' => 333444555,
            'first_name' => 'NeverNegative',
        ]);

        $wallet = WalletAccount::create([
            'user_id' => $user->id,
            'available_amount_rial' => 50000,
            'blocked_amount_rial' => 0,
            'version' => 1,
        ]);

        $action = app(DebitWalletAction::class);

        // Try to debit more than balance — 3 attempts
        for ($i = 0; $i < 3; $i++) {
            try {
                $action->execute(
                    $wallet, 50000, "Order {$i}", 'order', $i + 1,
                    'neg-test-' . $i . '-' . Str::uuid()->toString()
                );
            } catch (InsufficientBalanceException) {
                // Expected after first success
            }
        }

        $wallet->refresh();
        $this->assertGreaterThanOrEqual(0, $wallet->available_amount_rial);
        // Only one should have succeeded
        $this->assertSame(0, $wallet->available_amount_rial);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }
}
