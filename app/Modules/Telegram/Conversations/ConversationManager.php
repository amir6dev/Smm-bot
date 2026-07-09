<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Conversations;

class ConversationManager
{
    public function handle(array $message): void
    {
        // Skeleton logic for processing active text conversations based on Cache/DB state
        // For Phase 1 initial setup, routing logic will be expanded in later tasks
    }

    public function handleCallback(array $callbackQuery): void
    {
        // Skeleton logic for handling inline button interactions
    }
}
