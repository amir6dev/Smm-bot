<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Wallet\Actions\CreditWalletAction;
use App\Modules\Wallet\Models\WalletAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class WalletSeedBalanceCommand extends Command
{
    protected $signature = 'wallet:seed-balance {telegram_id : The user Telegram ID} {amount_toman : Amount in Toman to credit}';
    protected $description = 'Development only: Credit a user wallet with test balance';

    public function handle(CreditWalletAction $creditWalletAction): int
    {
        $telegramId = (int) $this->argument('telegram_id');
        $amountToman = (int) $this->argument('amount_toman');

        if ($amountToman <= 0) {
            $this->error('Amount must be a positive integer (in Toman).');
            return 1;
        }

        $user = User::where('telegram_id', $telegramId)->first();
        if (!$user) {
            $this->error("User with telegram_id {$telegramId} not found.");
            return 1;
        }

        $wallet = WalletAccount::where('user_id', $user->id)->first();
        if (!$wallet) {
            $this->error('User has no wallet account.');
            return 1;
        }

        // Convert Toman to Rial using integer multiplication
        $amountRial = $amountToman * 10;

        $creditWalletAction->execute(
            account: $wallet,
            amountRial: $amountRial,
            reason: "شارژ تستی توسط Artisan — {$amountToman} تومان",
            referenceType: 'admin_adjust',
            referenceId: $user->id,
            idempotencyKey: 'seed-' . Str::uuid()->toString(),
            actorType: 'admin',
        );

        $wallet->refresh();
        $newBalanceToman = intdiv($wallet->available_amount_rial, 10);

        $this->info("Credited {$amountToman} Toman ({$amountRial} Rial) to user {$telegramId}.");
        $this->info("New balance: {$newBalanceToman} Toman.");

        return 0;
    }
}
