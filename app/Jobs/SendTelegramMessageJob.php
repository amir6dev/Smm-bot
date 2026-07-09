<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Modules\Telegram\Services\TelegramApiService;

class SendTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $chatId,
        public string $text,
        public ?array $replyMarkup = null
    ) {}

    public function handle(TelegramApiService $telegramApiService): void
    {
        $telegramApiService->sendMessage($this->chatId, $this->text, $this->replyMarkup);
    }
}
