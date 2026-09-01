<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate-limits Telegram webhook requests to 60 per minute per IP.
 *
 * Returns a safe JSON 429 response when the limit is exceeded.
 * Does not expose any internal details in the response.
 */
class TelegramWebhookRateLimiter
{
    private const MAX_ATTEMPTS = 60;
    private const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): mixed
    {
        $key = 'telegram_webhook:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return response()->json(
                ['error' => 'Too many requests.'],
                429
            );
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return $next($request);
    }
}
