<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Telegram\Services\TelegramApiService;
use Illuminate\Console\Command;

class TelegramWebhookInfoCommand extends Command
{
    protected $signature = 'telegram:webhook-info';
    protected $description = 'Display current Telegram webhook configuration';

    public function handle(TelegramApiService $telegramApiService): int
    {
        try {
            $result = $telegramApiService->getWebhookInfo();
            $info = $result['result'] ?? $result;
            $this->table(
                ['Key', 'Value'],
                collect($info)->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : (string) $v])->toArray()
            );
        } catch (\Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }
}
