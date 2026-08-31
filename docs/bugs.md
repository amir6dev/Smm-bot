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

