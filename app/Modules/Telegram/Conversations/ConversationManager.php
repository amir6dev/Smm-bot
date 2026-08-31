<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Conversations;

use App\Models\User;
use App\Modules\Telegram\Models\ConversationState;

class ConversationManager
{
    private const DEFAULT_TTL_MINUTES = 30;

    /**
     * Handle an incoming text message within an active conversation.
     */
    public function handle(array $message): void
    {
        $telegramId = $message['from']['id'] ?? null;
        if (!$telegramId) {
            return;
        }

        $user = User::where('telegram_id', $telegramId)->first();
        if (!$user) {
            return;
        }

        $state = ConversationState::where('user_id', $user->id)->first();
        if (!$state || $state->isExpired()) {
            if ($state) {
                $state->delete();
            }
            return;
        }

        $this->routeToConversation($state, $user, $message);
    }

    /**
     * Handle an inline button callback within an active conversation.
     */
    public function handleCallback(array $callbackQuery): void
    {
        $telegramId = $callbackQuery['from']['id'] ?? null;
        if (!$telegramId) {
            return;
        }

        $user = User::where('telegram_id', $telegramId)->first();
        if (!$user) {
            return;
        }

        $callbackData = $callbackQuery['data'] ?? '';

        // Route confirmation callbacks directly even without active conversation state
        if (str_starts_with($callbackData, 'confirm:') || str_starts_with($callbackData, 'cancel:order')) {
            $conversation = app(PurchaseConversation::class);
            $state = ConversationState::where('user_id', $user->id)->first();
            $conversation->handleCallback($user, $callbackQuery, $state);
            return;
        }

        $state = ConversationState::where('user_id', $user->id)->first();
        if (!$state || $state->isExpired()) {
            if ($state) {
                $state->delete();
            }
            return;
        }

        $this->routeCallbackToConversation($state, $user, $callbackQuery);
    }

    /**
     * Start a new conversation for the user.
     */
    public function startConversation(User $user, string $handler, string $step, array $data = []): ConversationState
    {
        // Clear any existing conversation
        ConversationState::where('user_id', $user->id)->delete();

        return ConversationState::create([
            'user_id' => $user->id,
            'handler' => $handler,
            'step' => $step,
            'data_json' => $data,
            'expires_at' => now()->addMinutes(self::DEFAULT_TTL_MINUTES),
        ]);
    }

    /**
     * Clear the active conversation for a user.
     */
    public function clearConversation(User $user): void
    {
        ConversationState::where('user_id', $user->id)->delete();
    }

    /**
     * Route a text message to the appropriate conversation handler.
     */
    private function routeToConversation(ConversationState $state, User $user, array $message): void
    {
        $conversation = match ($state->handler) {
            'purchase' => app(PurchaseConversation::class),
            'order_tracking' => app(OrderTrackingConversation::class),
            default => null,
        };

        if ($conversation) {
            $conversation->handleMessage($user, $message, $state);
        }
    }

    /**
     * Route a callback query to the appropriate conversation handler.
     */
    private function routeCallbackToConversation(ConversationState $state, User $user, array $callbackQuery): void
    {
        $conversation = match ($state->handler) {
            'purchase' => app(PurchaseConversation::class),
            'order_tracking' => app(OrderTrackingConversation::class),
            default => null,
        };

        if ($conversation) {
            $conversation->handleCallback($user, $callbackQuery, $state);
        }
    }
}
