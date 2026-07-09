<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Modules\Telegram\Services\TelegramApiService;
use App\Modules\Telegram\Keyboards\MainMenuKeyboard;

class StartHandler
{
    public function __construct(
        private readonly TelegramApiService $telegramApiService
    ) {}

    public function handle(array $message): void
    {
        $chatId = (string) $message['chat']['id'];
        $telegramId = (int) $message['from']['id'];
        $firstName = $message['from']['first_name'] ?? null;
        $lastName = $message['from']['last_name'] ?? null;
        $username = $message['from']['username'] ?? null;

        // Register or update user
        $user = User::updateOrCreate(
            ['telegram_id' => $telegramId],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'username' => $username,
                'last_active_at' => now(),
            ]
        );

        // Ensure zero-balance wallet exists for new registrations
        if (!DB::table('wallet_accounts')->where('user_id', $user->id)->exists()) {
            DB::table('wallet_accounts')->insert([
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $displayName = $firstName ?? 'کاربر';
        $welcomeMessage = "سلام {$displayName} عزیز!\nبه پلتفرم خدمات SMM ما خوش آمدید.\nلطفاً از منوی زیر یک گزینه را انتخاب کنید:";

        \App\Jobs\SendTelegramMessageJob::dispatch($chatId, $welcomeMessage, MainMenuKeyboard::get());
    }
}
