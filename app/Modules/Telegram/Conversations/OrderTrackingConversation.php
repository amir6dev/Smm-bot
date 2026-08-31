<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Conversations;

use App\Jobs\SendTelegramMessageJob;
use App\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Services\OrderQueryService;
use App\Modules\Telegram\Keyboards\MainMenuKeyboard;
use App\Modules\Telegram\Models\ConversationState;

class OrderTrackingConversation
{
    public function __construct(
        private readonly OrderQueryService $orderQueryService,
    ) {}

    /**
     * Start order tracking — show recent orders.
     */
    public function start(User $user, string $chatId): void
    {
        $manager = app(ConversationManager::class);
        $manager->startConversation($user, 'order_tracking', 'viewing_orders');

        $this->showOrders($user, $chatId, 1);
    }

    /**
     * Handle text message — search by order code.
     */
    public function handleMessage(User $user, array $message, ConversationState $state): void
    {
        $chatId = (string) $message['chat']['id'];
        $text = trim($message['text'] ?? '');

        if (str_starts_with(strtoupper($text), 'ORD-')) {
            $this->searchByCode($user, $chatId, $text);
        } else {
            SendTelegramMessageJob::dispatch($chatId, "🔍 برای جستجو، کد سفارش خود را وارد کنید (مثال: ORD-20260830-A1B2C3)\n\nیا از منوی اصلی گزینه دیگری را انتخاب کنید.");
        }
    }

    /**
     * Handle callback — pagination.
     */
    public function handleCallback(User $user, array $callbackQuery, ?ConversationState $state): void
    {
        $chatId = (string) ($callbackQuery['message']['chat']['id'] ?? $user->telegram_id);
        $data = $callbackQuery['data'] ?? '';

        if (str_starts_with($data, 'orders_page:')) {
            $page = (int) substr($data, 12);
            $this->showOrders($user, $chatId, $page);
        } elseif (str_starts_with($data, 'order_detail:')) {
            $orderId = (int) substr($data, 13);
            $this->showOrderDetail($user, $chatId, $orderId);
        }
    }

    /**
     * Show paginated orders for the user.
     */
    private function showOrders(User $user, string $chatId, int $page): void
    {
        $orders = $this->orderQueryService->getOrdersForUser($user->id, 5);

        if ($orders->isEmpty()) {
            SendTelegramMessageJob::dispatch($chatId, '📭 شما هنوز سفارشی ثبت نکرده‌اید.', MainMenuKeyboard::get());
            return;
        }

        $message = "📋 <b>سفارشات شما</b>\n\n";
        foreach ($orders as $order) {
            $status = $order->status instanceof OrderStatus ? $order->status->label() : $order->status;
            $amountToman = intdiv($order->amount_rial, 10);
            $date = $order->created_at->format('Y/m/d H:i');
            $message .= "🔹 <b>{$order->order_code}</b>\n";
            $message .= "   وضعیت: {$status} | مبلغ: {$amountToman} تومان\n";
            $message .= "   تاریخ: {$date}\n\n";
        }

        $message .= "🔍 برای جستجو، کد سفارش خود را ارسال کنید.";

        $keyboard = ['inline_keyboard' => []];
        foreach ($orders as $order) {
            $keyboard['inline_keyboard'][] = [
                ['text' => "📄 جزئیات {$order->order_code}", 'callback_data' => "order_detail:{$order->id}"],
            ];
        }

        SendTelegramMessageJob::dispatch($chatId, $message, $keyboard);
    }

    /**
     * Search for order by code (user-scoped).
     */
    private function searchByCode(User $user, string $chatId, string $orderCode): void
    {
        $order = $this->orderQueryService->findByCodeForUser(strtoupper(trim($orderCode)), $user->id);

        if (!$order) {
            SendTelegramMessageJob::dispatch($chatId, '❌ سفارشی با این کد یافت نشد.');
            return;
        }

        $this->displayOrderDetail($chatId, $order);
    }

    /**
     * Show order detail by ID (user-scoped).
     */
    private function showOrderDetail(User $user, string $chatId, int $orderId): void
    {
        $order = $this->orderQueryService->findByIdForUser($orderId, $user->id);

        if (!$order) {
            SendTelegramMessageJob::dispatch($chatId, '❌ سفارش یافت نشد.');
            return;
        }

        $this->displayOrderDetail($chatId, $order);
    }

    /**
     * Format and display order details.
     */
    private function displayOrderDetail(string $chatId, $order): void
    {
        $status = $order->status instanceof OrderStatus ? $order->status->label() : $order->status;
        $amountToman = intdiv($order->amount_rial, 10);
        $snapshot = $order->snapshot_json ?? [];
        $serviceTitle = $snapshot['service_title'] ?? 'نامشخص';
        $date = $order->created_at->format('Y/m/d H:i');

        $detail = "📄 <b>جزئیات سفارش</b>\n\n";
        $detail .= "📝 کد سفارش: <code>{$order->order_code}</code>\n";
        $detail .= "🏷 سرویس: {$serviceTitle}\n";
        $detail .= "📊 تعداد: {$order->quantity}\n";
        $detail .= "💰 مبلغ: {$amountToman} تومان\n";
        $detail .= "📌 وضعیت: {$status}\n";
        $detail .= "📅 تاریخ ثبت: {$date}\n";

        if ($order->provider_order_id) {
            $detail .= "🔗 شناسه ارائه‌دهنده: {$order->provider_order_id}\n";
        }

        SendTelegramMessageJob::dispatch($chatId, $detail);
    }
}
