<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks Telegram update IDs to prevent duplicate processing.
 *
 * Uses Laravel's cache backend with a short TTL to detect and reject
 * Telegram updates that have already been processed. This protects
 * against duplicate wallet debits, order creation, and other
 * side-effecting operations caused by Telegram retrying delivery.
 */
final class TelegramUpdateIdTracker
{
    private const CACHE_PREFIX = 'telegram_update:';
    private const TTL_SECONDS = 300; // 5 minutes

    /**
     * Check if the given update_id has already been processed.
     * If not, mark it as processed and return false.
     * If yes, return true (duplicate).
     */
    public function isDuplicate(int $updateId): bool
    {
        $key = self::CACHE_PREFIX . $updateId;

        // Atomic add: returns true if key was successfully set (first time)
        $wasNew = Cache::add($key, true, self::TTL_SECONDS);

        // If add returned false, the key already existed → duplicate
        return !$wasNew;
    }
}
