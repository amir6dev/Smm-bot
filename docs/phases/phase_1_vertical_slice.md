# Phase 1 — Vertical Slice: Infrastructure, Webhook, Auth & Mock Purchase Flow

---

## 1. Phase Goal

Deliver a **fully working end-to-end purchase flow** from `/start` → service selection → order confirmation → mock wallet deduction → mock SMM submission → order tracking.  
By the end of this phase the following must be demonstrable on a real Telegram bot:

| Capability | Detail |
|---|---|
| Laravel 13 + PHP 8.4 project skeleton | Modular Monolith directory structure with `app/Modules/` |
| Telegram Webhook | Receives updates, validates secret token, responds < 3 s |
| User registration | `users` table with `telegram_id` as unique external identity |
| Conversation Engine | Multi-step state machine persisted in DB/cache with TTL |
| Catalog (seeded) | One category + two services seeded via migration/seeder |
| Order flow | Full purchase: input validation → price calculation → order creation |
| Mock Wallet deduction | `wallet_accounts` + `wallet_transactions` (append-only ledger) |
| Mock SMM Adapter | `MockProviderAdapter` implementing `SmmProviderContract`; returns fake `provider_order_id` |
| Idempotent confirmation | Action Token prevents duplicate orders on re-click |
| Order tracking | User can list own orders and query by `order_code` |
| Shared infrastructure | Enums, Value Objects, Idempotency helpers, Audit log skeleton |

> **Architectural Constraints enforced in this phase:**
> - All monetary values stored as `BIGINT` (Rial). UI shows Toman only.
> - Wallet ledger is **append-only**; no direct balance edits.
> - SMM API call is dispatched as a **Queue Job**, never inside the webhook response.
> - Order placement is **idempotent** (Action Token + DB transaction + row locking).

---

## 2. Modules & Architecture

### 2.1 Directory Structure Affected

```
smm-telegram-bot/
├── app/
│   ├── Modules/
│   │   ├── Telegram/
│   │   │   ├── Controllers/
│   │   │   │   └── TelegramWebhookController.php
│   │   │   ├── Handlers/
│   │   │   │   ├── StartHandler.php
│   │   │   │   ├── CallbackQueryHandler.php
│   │   │   │   └── MessageHandler.php
│   │   │   ├── Conversations/
│   │   │   │   ├── ConversationManager.php
│   │   │   │   └── PurchaseConversation.php
│   │   │   ├── Keyboards/
│   │   │   │   ├── MainMenuKeyboard.php
│   │   │   │   ├── CategoryKeyboard.php
│   │   │   │   ├── ServiceKeyboard.php
│   │   │   │   └── ConfirmOrderKeyboard.php
│   │   │   ├── Services/
│   │   │   │   └── TelegramApiService.php
│   │   │   └── Providers/
│   │   │       └── TelegramServiceProvider.php
│   │   │
│   │   ├── Catalog/
│   │   │   ├── Models/
│   │   │   │   ├── Category.php
│   │   │   │   └── Service.php
│   │   │   ├── Services/
│   │   │   │   ├── CatalogQueryService.php
│   │   │   │   └── PriceCalculator.php
│   │   │   └── Providers/
│   │   │       └── CatalogServiceProvider.php
│   │   │
│   │   ├── Orders/
│   │   │   ├── Models/
│   │   │   │   ├── Order.php
│   │   │   │   └── OrderEvent.php
│   │   │   ├── Enums/
│   │   │   │   └── OrderStatus.php
│   │   │   ├── Actions/
│   │   │   │   ├── PlaceOrderAction.php
│   │   │   │   └── RefundOrderAction.php
│   │   │   ├── Services/
│   │   │   │   └── OrderQueryService.php
│   │   │   └── Providers/
│   │   │       └── OrderServiceProvider.php
│   │   │
│   │   ├── Wallet/
│   │   │   ├── Models/
│   │   │   │   ├── WalletAccount.php
│   │   │   │   └── WalletTransaction.php
│   │   │   ├── Enums/
│   │   │   │   └── TransactionType.php
│   │   │   ├── Actions/
│   │   │   │   ├── CreditWalletAction.php
│   │   │   │   └── DebitWalletAction.php
│   │   │   └── Providers/
│   │   │       └── WalletServiceProvider.php
│   │   │
│   │   ├── Smm/
│   │   │   ├── Contracts/
│   │   │   │   └── SmmProviderContract.php
│   │   │   ├── Adapters/
│   │   │   │   └── MockProviderAdapter.php
│   │   │   ├── DTOs/
│   │   │   │   ├── NormalizedOrderPayload.php
│   │   │   │   ├── ProviderOrderResult.php
│   │   │   │   ├── ProviderOrderStatusResult.php
│   │   │   │   ├── ProviderBalanceResult.php
│   │   │   │   ├── ProviderActionResult.php
│   │   │   │   └── ConnectionResult.php
│   │   │   ├── Factory/
│   │   │   │   └── SmmProviderFactory.php
│   │   │   └── Providers/
│   │   │       └── SmmServiceProvider.php
│   │   │
│   │   └── Shared/
│   │       ├── ValueObjects/
│   │       │   └── Money.php
│   │       ├── Helpers/
│   │       │   └── IdempotencyGuard.php
│   │       ├── Enums/
│   │       │   └── ActorType.php
│   │       ├── Traits/
│   │       │   └── HasAuditLog.php
│   │       └── Models/
│   │           └── AuditLog.php
│   │
│   ├── Jobs/
│   │   └── SubmitSmmOrderJob.php
│   │
│   ├── Http/Controllers/Webhooks/
│   │   └── TelegramWebhookController.php  → delegates to Module
│   │
│   └── Providers/
│       └── AppServiceProvider.php
│
├── config/
│   ├── telegram.php
│   └── smm.php
│
├── database/
│   ├── migrations/
│   │   ├── 0001_create_users_table.php
│   │   ├── 0002_create_wallet_accounts_table.php
│   │   ├── 0003_create_wallet_transactions_table.php
│   │   ├── 0004_create_categories_table.php
│   │   ├── 0005_create_services_table.php
│   │   ├── 0006_create_smm_providers_table.php
│   │   ├── 0007_create_orders_table.php
│   │   ├── 0008_create_order_events_table.php
│   │   ├── 0009_create_conversation_states_table.php
│   │   └── 0010_create_audit_logs_table.php
│   ├── seeders/
│   │   ├── MockProviderSeeder.php
│   │   ├── CatalogSeeder.php
│   │   └── DatabaseSeeder.php
│   └── factories/
│       ├── UserFactory.php
│       ├── OrderFactory.php
│       └── WalletAccountFactory.php
│
├── routes/
│   ├── web.php          # Webhook route
│   └── console.php      # Scheduler
│
├── tests/
│   ├── Unit/
│   │   ├── PriceCalculatorTest.php
│   │   ├── MoneyValueObjectTest.php
│   │   └── OrderStatusEnumTest.php
│   ├── Feature/
│   │   ├── TelegramWebhookTest.php
│   │   ├── PurchaseFlowTest.php
│   │   ├── WalletDebitTest.php
│   │   └── IdempotentOrderTest.php
│   └── Integration/
│       └── SubmitSmmOrderJobTest.php
│
├── .env.example
└── composer.json
```

### 2.2 Module Dependency Graph

```
Telegram → Orders → Wallet (debit)
                  → Smm (dispatch job)
                  → Catalog (query)
Orders → Shared (idempotency, audit, Money VO)
Wallet → Shared (Money VO, audit)
```

> Modules communicate via **Actions** and **Services** — never by importing each other's Models directly for writes.

---

## 3. Database Schema

### 3.1 `users`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | Internal ID |
| `telegram_id` | `BIGINT UNSIGNED` | `UNIQUE NOT NULL` | Telegram user ID |
| `username` | `VARCHAR(255)` | `NULLABLE` | Display only; not for auth |
| `first_name` | `VARCHAR(255)` | `NULLABLE` | Display only |
| `phone_e164` | `VARCHAR(20)` | `UNIQUE NULLABLE` | E.164 format, added in Phase 2 |
| `phone_verified_at` | `TIMESTAMP` | `NULLABLE` | Set after OTP verification |
| `referred_by_user_id` | `BIGINT UNSIGNED` | `NULLABLE FK → users.id` | One-time assignment, immutable |
| `status` | `VARCHAR(20)` | `NOT NULL DEFAULT 'active'` | `active`, `banned` |
| `last_active_at` | `TIMESTAMP` | `NULLABLE` | Updated on each webhook |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `idx_users_status_created (status, created_at)`, `idx_users_last_active (last_active_at)`

### 3.2 `wallet_accounts`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `user_id` | `BIGINT UNSIGNED` | `UNIQUE NOT NULL FK → users.id` | One wallet per user |
| `available_amount_rial` | `BIGINT` | `NOT NULL DEFAULT 0` | **BIGINT Rial** — never float |
| `version` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | Optimistic locking counter |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

> Balance updates use `SELECT ... FOR UPDATE` inside a DB transaction. The `version` column provides an additional optimistic concurrency check.

### 3.3 `wallet_transactions`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `wallet_id` | `BIGINT UNSIGNED` | `NOT NULL FK → wallet_accounts.id` | |
| `type` | `VARCHAR(20)` | `NOT NULL` | `credit`, `debit`, `refund` |
| `amount_rial` | `BIGINT` | `NOT NULL` | Always positive; type indicates direction |
| `balance_after_rial` | `BIGINT` | `NOT NULL` | Snapshot of balance after this txn |
| `reference_type` | `VARCHAR(50)` | `NULLABLE` | Polymorphic: `order`, `invoice`, `admin_adjust`, `gift` |
| `reference_id` | `BIGINT UNSIGNED` | `NULLABLE` | FK to referenced entity |
| `reason` | `VARCHAR(500)` | `NOT NULL` | Human-readable reason |
| `idempotency_key` | `VARCHAR(100)` | `UNIQUE NOT NULL` | Prevents duplicate transactions |
| `actor_type` | `VARCHAR(20)` | `NOT NULL DEFAULT 'system'` | `user`, `admin`, `system` |
| `actor_id` | `BIGINT UNSIGNED` | `NULLABLE` | Who triggered this |
| `created_at` | `TIMESTAMP` | `NOT NULL` | **Immutable — no updated_at** |

**Indexes:** `idx_wt_wallet_created (wallet_id, created_at)`, `UNIQUE idx_wt_idempotency (idempotency_key)`

> ⚠️ **IMMUTABLE TABLE** — No `UPDATE` or `DELETE` operations are ever allowed. No `SoftDeletes`. Corrections are made by inserting a new reversal/refund row.

### 3.4 `categories`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `parent_id` | `BIGINT UNSIGNED` | `NULLABLE FK → categories.id` | For subcategories |
| `name` | `VARCHAR(255)` | `NOT NULL` | |
| `code` | `VARCHAR(50)` | `UNIQUE NOT NULL` | Slug identifier |
| `emoji` | `VARCHAR(10)` | `NULLABLE` | Display icon |
| `sort_order` | `INT` | `NOT NULL DEFAULT 0` | |
| `is_active` | `BOOLEAN` | `NOT NULL DEFAULT TRUE` | |
| `is_visible` | `BOOLEAN` | `NOT NULL DEFAULT TRUE` | Show in price list |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `idx_cat_parent_active_sort (parent_id, is_active, sort_order)`

### 3.5 `services`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `category_id` | `BIGINT UNSIGNED` | `NOT NULL FK → categories.id` | |
| `provider_id` | `BIGINT UNSIGNED` | `NOT NULL FK → smm_providers.id` | |
| `provider_service_id` | `VARCHAR(100)` | `NOT NULL` | Remote service ID on SMM panel |
| `title` | `VARCHAR(255)` | `NOT NULL` | |
| `description` | `TEXT` | `NULLABLE` | Ordering instructions |
| `price_per_1000_rial` | `BIGINT` | `NOT NULL` | **BIGINT Rial per 1000 units** |
| `cost_per_1000_rial` | `BIGINT` | `NOT NULL DEFAULT 0` | Buy price from panel |
| `min_quantity` | `INT UNSIGNED` | `NOT NULL DEFAULT 100` | |
| `max_quantity` | `INT UNSIGNED` | `NOT NULL DEFAULT 10000` | |
| `input_schema_json` | `JSON` | `NOT NULL` | Dynamic input fields definition |
| `is_active` | `BOOLEAN` | `NOT NULL DEFAULT FALSE` | |
| `sort_order` | `INT` | `NOT NULL DEFAULT 0` | |
| `estimated_time` | `VARCHAR(100)` | `NULLABLE` | e.g. "1-2 ساعت" |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `UNIQUE idx_svc_provider_svcid (provider_id, provider_service_id)`, `idx_svc_cat_active_sort (category_id, is_active, sort_order)`

### 3.6 `smm_providers`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `name` | `VARCHAR(255)` | `NOT NULL` | Display name |
| `code` | `VARCHAR(50)` | `UNIQUE NOT NULL` | Internal identifier — immutable after first use |
| `base_url` | `VARCHAR(500)` | `NOT NULL` | HTTPS only; validated |
| `api_profile` | `VARCHAR(20)` | `NOT NULL DEFAULT 'mock'` | `v1`, `v2`, `mock`, `custom` |
| `encrypted_token` | `TEXT` | `NOT NULL` | Laravel encrypted; never shown in UI |
| `timeout_seconds` | `INT UNSIGNED` | `NOT NULL DEFAULT 30` | |
| `max_retries` | `INT UNSIGNED` | `NOT NULL DEFAULT 3` | |
| `capabilities_json` | `JSON` | `NOT NULL` | Feature flags per provider |
| `status_mapping_json` | `JSON` | `NULLABLE` | External → canonical mapping |
| `is_active` | `BOOLEAN` | `NOT NULL DEFAULT FALSE` | |
| `last_health_check_at` | `TIMESTAMP` | `NULLABLE` | |
| `last_health_status` | `VARCHAR(20)` | `NULLABLE` | `healthy`, `degraded`, `down` |
| `last_balance` | `BIGINT` | `NULLABLE` | Provider balance in their unit |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

### 3.7 `orders`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `user_id` | `BIGINT UNSIGNED` | `NOT NULL FK → users.id` | |
| `service_id` | `BIGINT UNSIGNED` | `NOT NULL FK → services.id` | |
| `order_code` | `VARCHAR(30)` | `UNIQUE NOT NULL` | Human-readable: `ORD-20260704-AB12CD` |
| `quantity` | `INT UNSIGNED` | `NOT NULL` | |
| `amount_rial` | `BIGINT` | `NOT NULL` | **BIGINT — final charged amount in Rial** |
| `inputs_json` | `JSON` | `NOT NULL` | User inputs (link, username, etc.) |
| `snapshot_json` | `JSON` | `NOT NULL` | Frozen service name, price, limits, provider mapping |
| `status` | `VARCHAR(30)` | `NOT NULL DEFAULT 'draft'` | `OrderStatus` enum |
| `provider_order_id` | `VARCHAR(100)` | `NULLABLE` | Returned by SMM panel |
| `provider_status` | `VARCHAR(50)` | `NULLABLE` | Last raw status from panel |
| `action_token` | `VARCHAR(64)` | `NULLABLE UNIQUE` | One-time confirmation token |
| `action_token_expires_at` | `TIMESTAMP` | `NULLABLE` | TTL for confirmation |
| `submitted_at` | `TIMESTAMP` | `NULLABLE` | When sent to SMM panel |
| `completed_at` | `TIMESTAMP` | `NULLABLE` | |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `UNIQUE idx_ord_code (order_code)`, `idx_ord_user_status (user_id, status, created_at)`, `idx_ord_provider (provider_order_id)`, `UNIQUE idx_ord_action_token (action_token)`

### 3.8 `order_events`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `order_id` | `BIGINT UNSIGNED` | `NOT NULL FK → orders.id` | |
| `from_status` | `VARCHAR(30)` | `NULLABLE` | Previous status |
| `to_status` | `VARCHAR(30)` | `NOT NULL` | New status |
| `source` | `VARCHAR(30)` | `NOT NULL` | `user`, `system`, `admin`, `provider` |
| `payload_json` | `JSON` | `NULLABLE` | Redacted context data |
| `actor_id` | `BIGINT UNSIGNED` | `NULLABLE` | |
| `created_at` | `TIMESTAMP` | `NOT NULL` | **Append-only — no updated_at** |

**Indexes:** `idx_oe_order_created (order_id, created_at)`

### 3.9 `conversation_states`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `user_id` | `BIGINT UNSIGNED` | `NOT NULL FK → users.id` | |
| `handler` | `VARCHAR(100)` | `NOT NULL` | Conversation class name |
| `step` | `VARCHAR(50)` | `NOT NULL` | Current step identifier |
| `data_json` | `JSON` | `NULLABLE` | Accumulated conversation data |
| `expires_at` | `TIMESTAMP` | `NOT NULL` | Auto-cleanup after TTL |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `UNIQUE idx_cs_user (user_id)` — one active conversation per user

### 3.10 `audit_logs`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `actor_type` | `VARCHAR(20)` | `NOT NULL` | `user`, `admin`, `system` |
| `actor_id` | `BIGINT UNSIGNED` | `NULLABLE` | |
| `action` | `VARCHAR(100)` | `NOT NULL` | e.g. `order.placed`, `wallet.debited` |
| `subject_type` | `VARCHAR(100)` | `NULLABLE` | e.g. `Order`, `WalletAccount` |
| `subject_id` | `BIGINT UNSIGNED` | `NULLABLE` | |
| `before_json` | `JSON` | `NULLABLE` | State before change |
| `after_json` | `JSON` | `NULLABLE` | State after change |
| `context_json` | `JSON` | `NULLABLE` | IP, telegram_update_id, etc. |
| `created_at` | `TIMESTAMP` | `NOT NULL` | **Append-only** |

**Indexes:** `idx_al_actor (actor_type, actor_id, created_at)`, `idx_al_subject (subject_type, subject_id, created_at)`

---

## 4. Actionable Tasks

### 4.1 Project Skeleton & Configuration

- [ ] Initialize Laravel 13 project (`composer create-project laravel/laravel .`)
- [ ] Verify PHP 8.4 compatibility; set `composer.json` → `"php": "^8.4"`
- [ ] Configure `.env.example` with all required keys: `APP_KEY`, `DB_*`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_WEBHOOK_PATH`, `QUEUE_CONNECTION=database`
- [ ] Create `config/telegram.php` (bot token, webhook path, secret token, API base URL)
- [ ] Create `config/smm.php` (default provider profile, timeout defaults)
- [ ] Set up `database` queue driver: `php artisan queue:table && php artisan migrate`
- [ ] Create modular directory structure under `app/Modules/`
- [ ] Register module service providers in `bootstrap/providers.php`

### 4.2 Database Migrations & Seeders

- [ ] Create migration: `users` table (§3.1)
- [ ] Create migration: `wallet_accounts` table (§3.2)
- [ ] Create migration: `wallet_transactions` table (§3.3) — **no `updated_at`, no `SoftDeletes`**
- [ ] Create migration: `categories` table (§3.4)
- [ ] Create migration: `services` table (§3.5)
- [ ] Create migration: `smm_providers` table (§3.6)
- [ ] Create migration: `orders` table (§3.7)
- [ ] Create migration: `order_events` table (§3.8) — **no `updated_at`**
- [ ] Create migration: `conversation_states` table (§3.9)
- [ ] Create migration: `audit_logs` table (§3.10)
- [ ] Create `MockProviderSeeder`: one `smm_providers` row with `api_profile=mock`
- [ ] Create `CatalogSeeder`: one category "Instagram" + two services ("Follower", "Like") mapped to mock provider
- [ ] Create `DatabaseSeeder` that runs both seeders in order
- [ ] Verify: `php artisan migrate:fresh --seed` runs without errors

### 4.3 Shared Module

- [ ] Implement `Money` Value Object: constructor accepts `int` (Rial), `toToman()` for display, `fromToman(int)` static factory, arithmetic methods — **never float**
- [ ] Implement `IdempotencyGuard`: accepts a unique key → returns `true` if first time, `false` if already processed (uses `wallet_transactions.idempotency_key` or generic check)
- [ ] Implement `OrderStatus` Enum: `draft`, `awaiting_confirmation`, `queued`, `submitting`, `submitted`, `processing`, `completed`, `partial`, `manual_review`, `failed`, `refunded`, `cancelled`
- [ ] Implement `TransactionType` Enum: `credit`, `debit`, `refund`
- [ ] Implement `ActorType` Enum: `user`, `admin`, `system`
- [ ] Implement `AuditLog` model + `HasAuditLog` trait for recording changes
- [ ] Create helper: `generate_order_code()` → `ORD-YYYYMMDD-XXXXXX` (6 random alphanumeric)
- [ ] Create helper: `generate_action_token()` → 64-char cryptographically random string

### 4.4 Telegram Module

- [ ] Create `TelegramApiService` wrapping Laravel HTTP client for `sendMessage`, `editMessageText`, `answerCallbackQuery`, `getChatMember`, `setWebhook`, `getWebhookInfo`
- [ ] Create `TelegramWebhookController`: validate `X-Telegram-Bot-Api-Secret-Token` header, normalize `Update`, dispatch to handler, return `200 OK` immediately
- [ ] Register POST route: `/telegram/webhook/{secret_path}` in `routes/web.php` (with CSRF exemption)
- [ ] Implement `StartHandler`: create or update user, send main menu keyboard
- [ ] Implement `CallbackQueryHandler`: route `callback_data` to appropriate conversation/action
- [ ] Implement `MessageHandler`: route text messages to active conversation or ignore
- [ ] Implement `ConversationManager`: load/save/clear `conversation_states`, check TTL, dispatch to step handler
- [ ] Implement keyboard builders: `MainMenuKeyboard` (خرید سرویس, قیمت سرویس‌ها, شارژ حساب, اطلاعات حساب, زیرمجموعه‌گیری, پیگیری سفارش, ارتباط با ما)
- [ ] Implement `CategoryKeyboard`: list active categories as inline buttons
- [ ] Implement `ServiceKeyboard`: list active services in selected category
- [ ] Implement `ConfirmOrderKeyboard`: show summary + "تأیید نهایی" button with Action Token

### 4.5 Catalog Module

- [ ] Create `Category` model with `scopeActive()`, `scopeVisible()`, `children()`, `parent()` relationships
- [ ] Create `Service` model with `category()`, `provider()` relationships, `scopeActive()`, `scopeAvailableForPurchase()`
- [ ] Implement `CatalogQueryService`: get active categories, get services by category, get service by ID with validation
- [ ] Implement `PriceCalculator`: `calculate(int $quantity, int $pricePer1000Rial): int` — uses `ceil($quantity * $pricePer1000Rial / 1000)` — **integer arithmetic only**
- [ ] Implement `maxAffordableQuantity(int $balanceRial, int $pricePer1000Rial, int $maxQuantity): int`

### 4.6 Wallet Module

- [ ] Create `WalletAccount` model: auto-created on user registration, `$casts = ['available_amount_rial' => 'integer']`
- [ ] Create `WalletTransaction` model: **no `SoftDeletes`, no `updated_at`**, protected `$guarded = ['id']`
- [ ] Implement `DebitWalletAction`: accepts `(WalletAccount, int $amountRial, string $reason, string $referenceType, int $referenceId, string $idempotencyKey)`:
  1. Begin DB transaction
  2. `SELECT ... FOR UPDATE` on `wallet_accounts`
  3. Check `balance >= amount` (reject if insufficient)
  4. Check idempotency key uniqueness in `wallet_transactions`
  5. Insert `wallet_transactions` row with `type=debit`
  6. Update `wallet_accounts.available_amount_rial` and increment `version`
  7. Commit transaction
- [ ] Implement `CreditWalletAction`: same pattern but adds to balance (for seeding test balance and future payments)
- [ ] Ensure `WalletTransaction` model overrides `delete()` and `update()` to throw `LogicException`

### 4.7 SMM Module (Mock)

- [ ] Define `SmmProviderContract` interface:
  ```php
  interface SmmProviderContract {
      public function testConnection(): ConnectionResult;
      public function getBalance(): ProviderBalanceResult;
      public function getServices(): ProviderServiceCollection;
      public function createOrder(NormalizedOrderPayload $payload): ProviderOrderResult;
      public function getOrderStatus(string $providerOrderId): ProviderOrderStatusResult;
      public function cancelOrder(string $providerOrderId): ProviderActionResult;
      public function refillOrder(string $providerOrderId): ProviderActionResult;
  }
  ```
- [ ] Create DTOs: `NormalizedOrderPayload`, `ProviderOrderResult`, `ProviderOrderStatusResult`, `ProviderBalanceResult`, `ConnectionResult`, `ProviderActionResult`
- [ ] Implement `MockProviderAdapter`: returns success with random `provider_order_id`, simulates processing states
- [ ] Implement `SmmProviderFactory`: resolves adapter class from `smm_providers.api_profile`
- [ ] Register mock provider in DI container via `SmmServiceProvider`

### 4.8 Order Module & Purchase Flow

- [ ] Create `Order` model with `$casts`, relationships, `scopeForUser()`, status transition validation
- [ ] Create `OrderEvent` model — append-only, no `update()` or `delete()` allowed
- [ ] Implement `PlaceOrderAction`:
  1. Validate Action Token (exists, not expired, not used)
  2. Begin DB transaction
  3. Load service + provider (with validation)
  4. Calculate final price via `PriceCalculator`
  5. Call `DebitWalletAction` with idempotency key `order:{order_id}`
  6. Create `Order` with status `queued`
  7. Record `OrderEvent` (draft → queued)
  8. Mark Action Token as used
  9. Commit transaction
  10. Dispatch `SubmitSmmOrderJob` **after commit**
- [ ] Implement `RefundOrderAction`: credit wallet with `refund` type + new idempotency key
- [ ] Implement `PurchaseConversation`:
  - Step 1: Select category → show `CategoryKeyboard`
  - Step 2: Select service → show service details + price
  - Step 3: Collect dynamic inputs based on `input_schema_json`
  - Step 4: Collect quantity → validate min/max + affordability
  - Step 5: Show summary → `ConfirmOrderKeyboard` with Action Token (TTL: 5 minutes)
  - Step 6: On confirm → call `PlaceOrderAction`
- [ ] Implement order tracking: list orders (paginated), search by `order_code`

### 4.9 Artisan Commands

- [ ] `php artisan telegram:set-webhook` — registers webhook URL with Telegram
- [ ] `php artisan telegram:webhook-info` — calls `getWebhookInfo` and displays result
- [ ] `php artisan wallet:seed-balance {telegram_id} {amount_toman}` — for development testing

---

## 5. Jobs, Webhooks & Commands

### 5.1 Jobs

| Job | Queue | Trigger | Behavior |
|---|---|---|---|
| `SubmitSmmOrderJob` | `default` | After `PlaceOrderAction` commits | 1. Load order (assert status `queued`) 2. Transition to `submitting` 3. Resolve adapter via `SmmProviderFactory` 4. Call `createOrder()` 5. On success: save `provider_order_id`, transition to `submitted`, notify user 6. On retriable failure: leave as `queued`, release job with backoff 7. On final failure: transition to `failed`, call `RefundOrderAction`, notify user |
| `CleanExpiredConversationsJob` | `low` | Cron (hourly) | Delete `conversation_states` where `expires_at < now()` |

### 5.2 Webhook

| Endpoint | Method | Security | Responsibility |
|---|---|---|---|
| `POST /telegram/webhook/{secret_path}` | POST | HTTPS + `X-Telegram-Bot-Api-Secret-Token` header validation + rate limit (60/min per IP) | Receive Telegram updates. Validate → normalize → route to handler → respond `200 OK` within 3 seconds. No external API calls inside this request. |

### 5.3 Artisan Commands

| Command | Purpose |
|---|---|
| `telegram:set-webhook` | Register bot webhook URL with Telegram API |
| `telegram:webhook-info` | Display current webhook configuration |
| `wallet:seed-balance` | Dev-only: credit a user's wallet for testing |

### 5.4 Scheduler (console.php)

```php
Schedule::job(new CleanExpiredConversationsJob)->hourly();
```

---

## 6. Testing Strategy

### 6.1 Unit Tests

| Test | What it verifies |
|---|---|
| `PriceCalculatorTest` | Integer arithmetic: `quantity=500, price_per_1000=50000 → 25000`; edge cases: qty=1, qty=1000000; zero price; negative rejection |
| `MoneyValueObjectTest` | `fromToman(100)->toRial() === 1000`; arithmetic overflow protection; float rejection throws exception |
| `OrderStatusEnumTest` | All valid transitions allowed; invalid transitions rejected (e.g., `completed → draft`) |
| `OrderCodeGeneratorTest` | Format `ORD-YYYYMMDD-XXXXXX`; uniqueness over 1000 generations |
| `ActionTokenTest` | 64-char length; cryptographic randomness |

### 6.2 Feature Tests

| Test | What it verifies |
|---|---|
| `TelegramWebhookTest` | Valid secret → 200; missing/wrong secret → 403; malformed body → 400; rate limit → 429 |
| `PurchaseFlowTest` | Full flow: category select → service select → inputs → quantity → confirm → order created with correct amount, wallet debited, job dispatched |
| `WalletDebitTest` | Sufficient balance → debit succeeds, `balance_after` correct; insufficient balance → rejected; wallet transaction is immutable |
| `IdempotentOrderTest` | Same Action Token submitted twice → only one order created, only one wallet deduction, second request returns existing order |
| `OrderTrackingTest` | User sees only own orders; `order_code` search returns correct order; other user's order returns 404 |

### 6.3 Integration Tests

| Test | What it verifies |
|---|---|
| `SubmitSmmOrderJobTest` | Job with `MockProviderAdapter` → order transitions to `submitted`, `provider_order_id` saved |
| `SubmitSmmOrderJobFailureTest` | Adapter throws → job retries; after max retries → order `failed`, wallet refunded |
| `ConcurrentDebitTest` | Two simultaneous debits on same wallet → only one succeeds (row locking) |

---

## 7. Acceptance Criteria

| # | Criterion | Verification Method |
|---|---|---|
| AC-1 | `/start` on Telegram creates a `users` row with `telegram_id` and shows the main menu | Manual: send `/start`, check DB |
| AC-2 | "خرید سرویس" shows seeded categories, then services | Manual: tap through menu |
| AC-3 | Entering a quantity below `min_quantity` is rejected with clear Farsi error | Manual: enter 1 for a service with min=100 |
| AC-4 | Order summary shows correct amount in Toman (integer division, no float) | Manual: verify displayed vs calculated |
| AC-5 | Confirming order deducts exact amount from wallet and creates order with status `queued` | Query DB: `wallet_transactions` + `orders` |
| AC-6 | `SubmitSmmOrderJob` runs and transitions order to `submitted` with a `provider_order_id` | Check `orders` table + `order_events` |
| AC-7 | Re-clicking the confirm button returns "سفارش قبلاً ثبت شده" — no duplicate deduction | Manual: double-click + verify single `wallet_transactions` row |
| AC-8 | "پیگیری سفارش" shows the user's order with code, status, and amount | Manual: tap "پیگیری سفارش" |
| AC-9 | User A cannot see User B's orders by guessing order codes or IDs | Feature test: `OrderTrackingTest` |
| AC-10 | All `wallet_transactions` rows are immutable — `UPDATE`/`DELETE` throws exception | Unit test: attempt update on model |
| AC-11 | All amounts in DB are `BIGINT` — no `DECIMAL`, `FLOAT`, or `DOUBLE` columns | Migration review + `SHOW COLUMNS` check |
| AC-12 | Webhook responds within 3 seconds; SMM call happens asynchronously in queue | Feature test with timing assertion |
| AC-13 | `php artisan migrate:fresh --seed` runs cleanly on empty DB | CI/local execution |
| AC-14 | All tests pass: `php artisan test` exits 0 | CI/local execution |
