<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use App\Modules\Telegram\Handlers\StartHandler;
use App\Modules\Telegram\Handlers\MessageHandler;
use App\Modules\Telegram\Handlers\CallbackQueryHandler;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly StartHandler $startHandler,
        private readonly MessageHandler $messageHandler,
        private readonly CallbackQueryHandler $callbackQueryHandler,
    ) {}

    public function handle(Request $request): Response
    {
        $secretToken = $request->header('X-Telegram-Bot-Api-Secret-Token');
        
        if ($secretToken !== config('telegram.webhook_secret')) {
            Log::warning('Unauthorized Telegram webhook access attempt.', ['ip' => $request->ip()]);
            abort(403, 'Unauthorized');
        }

        $update = $request->all();

        if (isset($update['message']['text'])) {
            $text = $update['message']['text'];
            
            if (str_starts_with($text, '/start')) {
                $this->startHandler->handle($update['message']);
            } else {
                $this->messageHandler->handle($update['message']);
            }
        } elseif (isset($update['callback_query'])) {
            $this->callbackQueryHandler->handle($update['callback_query']);
        }

        return response('OK', 200);
    }
}
