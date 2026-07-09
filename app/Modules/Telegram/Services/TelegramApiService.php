<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;

class TelegramApiService
{
    private string $baseUrl;

    public function __construct()
    {
        $token = config('telegram.bot_token');
        $this->baseUrl = "https://api.telegram.org/bot{$token}/";
    }

    /**
     * @throws RequestException
     */
    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        $response = Http::post($this->baseUrl . 'sendMessage', $payload);
        
        $response->throw();

        return $response->json();
    }

    /**
     * @throws RequestException
     */
    public function getWebhookInfo(): array
    {
        $response = Http::get($this->baseUrl . 'getWebhookInfo');
        
        $response->throw();
        
        return $response->json();
    }

    /**
     * @throws RequestException
     */
    public function setWebhook(string $url, string $secretToken): array
    {
        $response = Http::post($this->baseUrl . 'setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
        ]);
        
        $response->throw();
        
        return $response->json();
    }
}
