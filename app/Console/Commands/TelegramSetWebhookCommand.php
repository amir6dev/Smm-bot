<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Telegram\Services\TelegramApiService;
use Illuminate\Console\Command;

class TelegramSetWebhookCommand extends Command
{
    protected $signature = 'telegram:set-webhook {url? : The webhook URL}';
    protected $description = 'Register the Telegram bot webhook URL';

    public function handle(TelegramApiService $telegramApiService): int
    {
        $url = $this->argument('url') ?? config('app.url') . '/' . config('telegram.webhook_path');
        $secret = config('telegram.webhook_secret');

        if (empty($secret)) {
            $this->error('TELEGRAM_WEBHOOK_SECRET is not configured.');
            return 1;
        }

        $this->info("Setting webhook to: {$url}");

        try {
            $result = $telegramApiService->setWebhook($url, $secret);
            $this->info('Webhook set successfully.');
            $this->table(['Key', 'Value'], collect($result)->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : (string) $v])->toArray());
        } catch (\Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }
}
