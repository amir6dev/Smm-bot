# Bug Tracker

This document strictly tracks all test failures, logical bugs, and architectural constraints missed during implementation, along with their resolutions.

---

### **Date:** 2026-07-09
- **Bug Description:** The `User::updateOrCreate()` method in `StartHandler` and `User::create()` in `WalletActionsTest` would fail with a `MassAssignmentException` because the default Laravel `App\Models\User` did not have `telegram_id`, `first_name`, `last_name`, `username`, and `last_active_at` in its `$fillable` array to match our custom `users` migration.
- **Affected Component:** `App\Models\User`
- **Resolution:** Overwrote the default `App\Models\User` to explicitly include all new migration fields in `$fillable`, added `$casts` for `last_active_at`, and mapped the `walletAccount()` relationship.

---

### **Date:** 2026-08-30 (Discovered during Step 5 research)

- **Bug Description:** Migrations for `categories`, `services`, `smm_providers`, and `conversation_states` tables were never created despite being specified in Phase 1 §3.4–§3.6, §3.9 and referenced by existing Catalog models (`Category.php`, `Service.php`).
- **Affected Component:** `database/migrations`, `App\Modules\Catalog`, `App\Modules\Smm`, `App\Modules\Telegram\Conversations`
- **Resolution:** Created new migrations `2026_08_30_000001` through `2026_08_30_000004` for all four tables in Step 5.

- **Bug Description:** The `orders` migration is missing `action_token_expires_at` (TIMESTAMP NULLABLE) and `submitted_at` (TIMESTAMP NULLABLE) columns required by Phase 1 spec §3.7 for Action Token TTL enforcement and submission tracking.
- **Affected Component:** `database/migrations/2026_07_09_000004_create_orders_table.php`
- **Resolution:** Created additive migration `2026_08_30_000005` to add both columns without modifying the original migration.

- **Bug Description:** The `order_events` migration is missing the `actor_id` column required by Phase 1 spec §3.8 for tracking who triggered the event.
- **Affected Component:** `database/migrations/2026_07_09_000005_create_order_events_table.php`
- **Resolution:** Created additive migration `2026_08_30_000006` to add `actor_id`.

- **Bug Description:** No `config/telegram.php` or `config/smm.php` configuration files exist. `TelegramApiService` references `config('telegram.bot_token')` and `TelegramWebhookController` references `config('telegram.webhook_secret')` but these config files were never created.
- **Affected Component:** `config/`, `App\Modules\Telegram\Services\TelegramApiService`
- **Resolution:** Created both config files in Step 5.

- **Bug Description:** No webhook route is registered in `routes/web.php`. The Telegram webhook endpoint has no routing entry.
- **Affected Component:** `routes/web.php`
- **Resolution:** Added POST route for webhook in Step 5.

- **Bug Description:** No database seeders exist (`MockProviderSeeder`, `CatalogSeeder`, `DatabaseSeeder`). Required for `php artisan migrate:fresh --seed`.
- **Affected Component:** `database/seeders/`
- **Resolution:** Created all three seeders in Step 5.

- **Bug Description:** `Service` model is missing `provider()` relationship to `SmmProvider`, even though the `services` table has a `provider_id` FK.
- **Affected Component:** `App\Modules\Catalog\Models\Service`
- **Resolution:** Added `provider()` BelongsTo relationship in Step 5.

- **Bug Description:** `OrderEvent` model is missing `order()` relationship and proper `$casts`.
- **Affected Component:** `App\Modules\Orders\Models\OrderEvent`
- **Resolution:** Added relationship and casts in Step 5.

---

### **Date:** 2026-09-06 (Discovered during Step 6 security audit)

- **Bug ID:** SEC-1
- **Bug Description:** `TelegramWebhookController` used `abort(403)` which throws an HTML exception page, potentially exposing stack traces, internal file paths, and framework version in non-production environments. Also, the secret comparison used `!==` instead of `hash_equals()`, making it vulnerable to timing attacks.
- **Affected Component:** `App\Modules\Telegram\Controllers\TelegramWebhookController`
- **Resolution:** Replaced `abort(403)` with `response()->json(['status' => 'error'], 403)`. Replaced `!==` with `hash_equals()`. Added `empty()` checks for both expected secret and provided token.

- **Bug ID:** SEC-2
- **Bug Description:** No request body validation on webhook endpoint. Malformed JSON or missing `update_id` field was silently accepted with HTTP 200, causing Telegram to consider garbage payloads "processed."
- **Affected Component:** `App\Modules\Telegram\Controllers\TelegramWebhookController`
- **Resolution:** Added validation: `empty($update) || !isset($update['update_id']) || !is_numeric($update['update_id'])` → returns 400 JSON.

- **Bug ID:** SEC-3
- **Bug Description:** Webhook endpoint had no rate limiting. Completely unprotected against flood attacks.
- **Affected Component:** `routes/web.php`, Telegram webhook
- **Resolution:** Created `TelegramWebhookRateLimiter` middleware (60 req/min/IP) and applied to webhook route.

- **Bug ID:** SEC-4
- **Bug Description:** No duplicate `update_id` protection. The same Telegram update could trigger handlers multiple times if Telegram retried delivery, potentially causing duplicate wallet debits or order creation.
- **Affected Component:** `App\Modules\Telegram\Controllers\TelegramWebhookController`
- **Resolution:** Created `TelegramUpdateIdTracker` service using Laravel cache with 5-minute TTL. Integrated into webhook controller.

- **Bug ID:** SEC-5
- **Bug Description:** Handler exceptions in the webhook controller were uncaught, causing Laravel's default exception handler to return HTML error pages with potential stack traces and internal details.
- **Affected Component:** `App\Modules\Telegram\Controllers\TelegramWebhookController`
- **Resolution:** Wrapped handler dispatch in `try/catch (Throwable)`, returning safe JSON `['status' => 'error']` with HTTP 500. Errors logged securely with `update_id` context.

- **Bug ID:** BUG-6
- **Bug Description:** `Money::toToman()` used `(int)($this->amountRial / 10)` which performs float division before int cast. For certain edge-case values, float precision errors could produce incorrect results.
- **Affected Component:** `App\Modules\Shared\ValueObjects\Money`
- **Resolution:** Changed to `intdiv($this->amountRial, 10)` for strict integer division. Added overflow protection on `fromToman()` and `fromRial()`/`toRial()` convenience methods.

- **Bug ID:** BUG-7
- **Bug Description:** `SubmitSmmOrderJob` had `$tries = 1`, meaning any provider exception (network timeout, 5xx error) would immediately fail the order and refund. No retry capability existed.
- **Affected Component:** `App\Jobs\SubmitSmmOrderJob`
- **Resolution:** Changed to `$tries = 3` with `$backoff = [10, 60]` for exponential backoff. Retriable failures (exceptions) now re-throw for Laravel retry mechanism. Added `failed()` method for terminal failure handling with refund. Non-retriable failures (explicit `ProviderOrderResult::failure()`) remain immediately terminal.
