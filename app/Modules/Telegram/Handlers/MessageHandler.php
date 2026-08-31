<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Jobs\SendTelegramMessageJob;
use App\Models\User;
use App\Modules\Telegram\Conversations\ConversationManager;
use App\Modules\Telegram\Conversations\OrderTrackingConversation;
use App\Modules\Telegram\Conversations\PurchaseConversation;

class MessageHandler
{
    public function __construct(
        private readonly ConversationManager $conversationManager,
    ) {}

    /**
     * Route incoming text messages to the appropriate handler.
     */
    public function handle(array $message): void
    {
        $text = trim($message['text'] ?? '');
        $telegramId = $message['from']['id'] ?? null;

        if (!$telegramId) {
            return;
        }

        $user = User::where('telegram_id', $telegramId)->first();
        if (!$user) {
            return;
        }

        $chatId = (string) $message['chat']['id'];

        // Route main menu buttons
        match ($text) {
            'خرید سرویس' => $this->startPurchase($user, $chatId),
            'پیگیری سفارش' => $this->startOrderTracking($user, $chatId),
            'اطلاعات حساب' => $this->showAccountInfo($user, $chatId),
            default => $this->conversationManager->handle($message),
        };
    }

    private function startPurchase(User $user, string $chatId): void
    {
        $conversation = app(PurchaseConversation::class);
        $conversation->showCategories($user, $chatId);
    }

    private function startOrderTracking(User $user, string $chatId): void
    {
        $conversation = app(OrderTrackingConversation::class);
        $conversation->start($user, $chatId);
    }

    private function showAccountInfo(User $user, string $chatId): void
    {
        $wallet = $user->walletAccount;
        $balance = $wallet ? intdiv($wallet->available_amount_rial, 10) : 0;

        $info = "👤 <b>اطلاعات حساب</b>\n\n";
        $info .= "🆔 شناسه تلگرام: <code>{$user->telegram_id}</code>\n";
        $info .= "👤 نام: {$user->first_name} {$user->last_name}\n";
        $info .= "💰 موجودی: {$balance} تومان\n";

        SendTelegramMessageJob::dispatch($chatId, $info);
    }
}
