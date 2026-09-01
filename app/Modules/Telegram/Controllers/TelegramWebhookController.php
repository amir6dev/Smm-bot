<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Modules\Telegram\Handlers\StartHandler;
use App\Modules\Telegram\Handlers\MessageHandler;
use App\Modules\Telegram\Handlers\CallbackQueryHandler;
use App\Modules\Telegram\Services\TelegramUpdateIdTracker;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly StartHandler $startHandler,
        private readonly MessageHandler $messageHandler,
        private readonly CallbackQueryHandler $callbackQueryHandler,
        private readonly TelegramUpdateIdTracker $updateIdTracker,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        // Step 1: Validate webhook secret
        $secretToken = $request->header('X-Telegram-Bot-Api-Secret-Token');
        $expectedSecret = config('telegram.webhook_secret');

        if (empty($expectedSecret) || empty($secretToken) || !hash_equals($expectedSecret, $secretToken)) {
            Log::warning('Unauthorized Telegram webhook access attempt.', ['ip' => $request->ip()]);
            return response()->json(['status' => 'error'], 403);
        }

        // Step 2: Validate request body structure
        $update = $request->all();

        if (empty($update) || !isset($update['update_id']) || !is_numeric($update['update_id'])) {
            return response()->json(['status' => 'error'], 400);
        }

        // Step 3: Check for duplicate update_id
        $updateId = (int) $update['update_id'];
        if ($this->updateIdTracker->isDuplicate($updateId)) {
            // Already processed — return 200 so Telegram doesn't retry
            return response()->json(['status' => 'ok'], 200);
        }

        // Step 4: Route to appropriate handler
        try {
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
        } catch (Throwable $e) {
            // Log the error securely — never expose details in response
            Log::error('Telegram webhook processing error.', [
                'update_id' => $updateId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => 'ok'], 200);
    }
}
