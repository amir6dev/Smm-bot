<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Telegram\Conversations\ConversationManager;

class CallbackQueryHandler
{
    public function __construct(
        private readonly ConversationManager $conversationManager,
    ) {}

    /**
     * Route incoming callback queries.
     */
    public function handle(array $callbackQuery): void
    {
        $this->conversationManager->handleCallback($callbackQuery);
    }
}
