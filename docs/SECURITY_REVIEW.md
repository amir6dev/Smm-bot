# SMM BOT — Security & Bug Review

**Reviewer:** Claude Opus 4 — Senior Security / Laravel / Backend Code Review
**Date:** 2026-09-17
**Scope:** Full Phase 1 codebase (Steps 1–7)
**Repository:** `amir6dev/Smm-bot`

---

## 1. Executive Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 1 |
| HIGH | 3 |
| MEDIUM | 6 |
| LOW | 4 |
| INFO | 3 |
| **Total** | **17** |

**Overall Risk:** MEDIUM-HIGH

**Production Readiness:** NOT READY — one CRITICAL bug completely blocks the purchase confirmation flow.

**Most Important Risks:**
1. **CRITICAL:** Action token exceeds Telegram's 64-byte `callback_data` limit — purchase flow is broken
2. **HIGH:** No HTTP timeouts on `TelegramApiService` — webhook/jobs can hang indefinitely
3. **HIGH:** Rate limiter keyed by IP — ineffective when Telegram sends from its own IP ranges
4. **HIGH:** `SubmitSmmOrderJob` has no `$timeout` — job hangs silently on provider failure

The codebase demonstrates strong architectural foundations: modular monolith, wallet concurrency protection via `lockForUpdate()`, append-only immutable ledgers, idempotency keys with DB UNIQUE constraints, and typed DTOs. However, the CRITICAL callback_data bug must be fixed before any real user interaction is possible.

---

## 2. Scope

**Files Inspected:** 50 PHP source files, 11 migrations, 3 seeders, 16 test files, 2 config files, 2 route files, 3 documentation files.

**Modules Inspected:**
- `app/Modules/Telegram/` — Controllers, Handlers, Conversations, Keyboards, Middleware, Services, Models
- `app/Modules/Wallet/` — Actions, Models, Enums, Exceptions
- `app/Modules/Orders/` — Actions, Models, Enums, Services, Exceptions
- `app/Modules/Catalog/` — Models, Services
- `app/Modules/Smm/` — Adapters, Contracts, DTOs, Factories, Models
- `app/Modules/Shared/` — ValueObjects, Helpers, Enums
- `app/Jobs/`, `app/Models/`, `app/Console/Commands/`
- `database/migrations/`, `database/seeders/`
- `routes/`, `config/`, `tests/`

**Security-Specific Searches Performed:**
- `dd()`, `dump()`, `var_dump()`, `print_r()` → none found
- `(float)`, `(double)` → none found
- `->increment()`, `->decrement()` → none found
- `DB::raw`, `selectRaw`, `whereRaw` → none found
- `$request->all()`, `->fill()` → none found
- `TELEGRAM_BOT_TOKEN` in app code → only in config, not in business logic
- `TODO`, `FIXME` → none found
- `afterCommit`, `ShouldBeUnique` → not implemented on jobs
- `->timeout()`, `->connectTimeout()` → none found on HTTP clients

---

## 3. Methodology

1. Inspect all source files systematically
2. Trace complete data flows (Telegram → Controller → Handler → Action → DB → Job → Provider)
3. Search for known vulnerability patterns using grep
4. Verify documented security claims against actual code
5. Analyze concurrency scenarios for race conditions
6. Verify idempotency mechanisms (application-level + DB-level)
7. Audit mass assignment, input validation, and authorization
8. Check financial arithmetic integrity
9. Verify secret handling and information leakage
10. Assess test coverage against security-critical paths

---

## 4. Critical Findings

### SEC-001: Action Token Exceeds Telegram callback_data 64-Byte Limit

```
ID:       SEC-001
Severity: CRITICAL
Category: Functionality / Telegram Integration
Title:    Action token in callback_data exceeds Telegram's 64-byte limit — purchase flow is broken
```

**Location:**
- `app/Modules/Shared/Helpers/ActionTokenGenerator.php` → `generate()`
- `app/Modules/Telegram/Keyboards/ConfirmOrderKeyboard.php` → `build()`

**Evidence:**

`ActionTokenGenerator::generate()` produces a 64-character hex string:
```php
return bin2hex(random_bytes(32)); // → 64 hex characters
```

`ConfirmOrderKeyboard::build()` constructs callback_data as:
```php
'callback_data' => "confirm:{$actionToken}"
// "confirm:" (8 bytes) + token (64 bytes) = 72 bytes total
```

Telegram Bot API enforces: `callback_data` must be **1–64 bytes**.

72 bytes > 64 bytes → **Telegram API will reject the `sendMessage` call** with a `BUTTON_DATA_INVALID` error.

**Comment–Code Mismatch (line 14):**
```php
// Only send first 32 chars of token as callback data (Telegram limit is 64 bytes)
// The full token is stored in conversation state
```
But the code sends the **full** 64-char token. The comment describes intended behavior that was never implemented.

**Problem:**
The `SendTelegramMessageJob` dispatched by `PurchaseConversation::showSummary()` will fail when calling the Telegram API. The user will never see the confirmation keyboard. **No order can be confirmed through the normal purchase flow.**

**Attack/Failure Scenario:**
1. User selects service, enters inputs, enters quantity
2. `showSummary()` generates a 64-char action token
3. `ConfirmOrderKeyboard::build($actionToken)` creates 72-byte callback_data
4. `SendTelegramMessageJob` calls `TelegramApiService::sendMessage()` with the keyboard
5. Telegram API returns error: `BUTTON_DATA_INVALID`
6. Job fails — user never sees confirmation button
7. Purchase flow is dead

**Expected Behavior:**
The callback_data must be ≤ 64 bytes total. The token sent in callback_data should be shortened, and verified against the full token stored in conversation state.

**Recommended Fix:**
```php
// Option A: Shorter token (28 hex chars = 56 bytes + 8 prefix = 64 bytes)
// ActionTokenGenerator.php
return bin2hex(random_bytes(14)); // 28 hex chars

// Option B: Send truncated token, verify with str_starts_with
// ConfirmOrderKeyboard.php
$shortToken = substr($actionToken, 0, 56);
'callback_data' => "confirm:{$shortToken}"

// PurchaseConversation::confirmOrder()
if (!str_starts_with($storedToken, $actionToken)) { ... }
```

**Regression Test:**
- Test that `ConfirmOrderKeyboard::build()` never produces callback_data > 64 bytes
- End-to-end test verifying confirm keyboard is sendable via Telegram API

---

## 5. High Findings

### SEC-002: TelegramApiService Has No HTTP Timeouts

```
ID:       SEC-002
Severity: HIGH
Category: Reliability / Denial of Service
Title:    HTTP client calls have no timeout — potential infinite hangs
```

**Location:**
- `app/Modules/Telegram/Services/TelegramApiService.php` → `sendMessage()`, `getWebhookInfo()`, `setWebhook()`

**Evidence:**
```php
$response = Http::post($this->baseUrl . 'sendMessage', $payload);
$response = Http::get($this->baseUrl . 'getWebhookInfo');
$response = Http::post($this->baseUrl . 'setWebhook', [...]);
```

No `->timeout()` or `->connectTimeout()` is configured. Laravel's `Http` facade uses PHP's default stream timeout, which can be 30+ seconds or indefinite.

**Problem:**
If Telegram's API is slow or unresponsive:
- `SendTelegramMessageJob` hangs → queue workers are blocked
- `telegram:webhook-info` and `telegram:set-webhook` commands hang
- Blocked workers mean no messages sent to users, no order confirmations

**Recommended Fix:**
```php
Http::timeout(10)->connectTimeout(5)->post(...)
```

**Regression Test:** Unit test verifying timeout exception is thrown when API is slow.

---

### SEC-003: Rate Limiter Keyed by IP — Ineffective for Telegram Webhooks

```
ID:       SEC-003
Severity: HIGH
Category: Rate Limiting / Availability
Title:    Rate limiter uses request IP — all Telegram webhooks share the same bucket
```

**Location:**
- `app/Modules/Telegram/Middleware/TelegramWebhookRateLimiter.php`

**Evidence:**
```php
$key = 'telegram_webhook:' . $request->ip();
```

Telegram sends **all** webhook updates from a small set of Telegram-owned IP addresses. Every legitimate update from every user shares the same rate limit bucket.

**Problem:**
- 60 requests/minute/IP means 60 total Telegram updates per minute across ALL users
- A bot with even moderate traffic (>1 msg/sec) will be rate-limited
- Legitimate Telegram updates will receive 429 responses
- Telegram will retry, increasing load, causing cascading failures
- An attacker can exhaust the limit by sending rapid messages to the bot

**Recommended Fix:**
Rate limit per Telegram user ID (from the update payload) after secret validation, or remove IP-based limiting for authenticated webhook traffic.

**Regression Test:** Test that 61 requests from different Telegram users are NOT rate-limited.

---

### SEC-004: SubmitSmmOrderJob Has No $timeout Property

```
ID:       SEC-004
Severity: HIGH
Category: Reliability / Queue Safety
Title:    SubmitSmmOrderJob can hang indefinitely on provider failure
```

**Location:**
- `app/Jobs/SubmitSmmOrderJob.php`

**Evidence:**
The job defines `$tries = 3` and `$backoff = [10, 60]` but no `$timeout`. MockProviderAdapter uses `usleep(100_000)` (100ms), but real providers could hang for minutes.

**Problem:**
If a provider HTTP endpoint hangs, the job runs indefinitely. Queue worker slot is consumed. Other orders pile up. The `failed()` method is never called. No refund occurs.

**Recommended Fix:**
```php
public int $timeout = 60;
```

**Regression Test:** Assert `$timeout` property exists with a reasonable value.

---

## 6. Medium Findings

### SEC-005: Order Model Has Sensitive Fields in $fillable

```
ID:       SEC-005
Severity: MEDIUM
Category: Mass Assignment
Title:    Order.$fillable includes status, amount_rial, provider_order_id
```

**Location:** `app/Modules/Orders/Models/Order.php`

**Evidence:**
```php
protected $fillable = [
    'user_id', 'service_id', 'order_code', 'status',
    'quantity', 'amount_rial', 'target_data_json', 'snapshot_json',
    'action_token', 'action_token_expires_at', 'provider_order_id',
    'provider_status', 'remains', 'start_count', 'submitted_at', 'completed_at',
];
```

Fields like `status`, `amount_rial`, `provider_order_id` should never be settable from user input. While currently only set via `PlaceOrderAction` (server-side computation), the broad `$fillable` creates risk if future code uses `Order::create($request->all())`.

**Recommended Fix:** Switch to `$guarded = ['id']` or narrow `$fillable`.

---

### SEC-006: orders.service_id Lacks Foreign Key Constraint

```
ID:       SEC-006
Severity: MEDIUM
Category: Database Integrity
Title:    orders.service_id has no FK — orphan references possible
```

**Location:** `database/migrations/2026_07_09_000004_create_orders_table.php`

**Evidence:**
```php
$table->unsignedBigInteger('service_id');
// No ->constrained('services') or ->foreign(...)
```

An order can reference a `service_id` that doesn't exist in the services table.

**Recommended Fix:** Add FK via additive migration.

---

### SEC-007: SendTelegramMessageJob Has No Retry Limits

```
ID:       SEC-007
Severity: MEDIUM
Category: Queue Safety
Title:    SendTelegramMessageJob has no $tries, $backoff, or $timeout
```

**Location:** `app/Jobs/SendTelegramMessageJob.php`

**Evidence:** No `$tries`, `$backoff`, `$timeout`, or `$maxExceptions` properties defined.

**Problem:** If Telegram API is temporarily down, messages fail silently with default tries. If `$tries` is configured globally to a high value, the job could flood Telegram's API.

**Recommended Fix:**
```php
public int $tries = 3;
public int $timeout = 15;
public array $backoff = [5, 30];
```

---

### SEC-008: No Input Length Validation on Dynamic Text Inputs

```
ID:       SEC-008
Severity: MEDIUM
Category: Input Validation
Title:    Dynamic input fields have no length limits
```

**Location:** `app/Modules/Telegram/Conversations/PurchaseConversation.php` → `processInput()`

**Evidence:**
```php
if (trim($text) === '') { ... }            // emptiness check ✅
if ($type === 'url' && ...) { ... }        // URL format check ✅
// No max length check ❌
```

A user can send a 4096-character message as input. This gets stored in `target_data_json` and `snapshot_json` without truncation. Accumulated over many orders, this causes excessive database storage.

**Recommended Fix:** Add `mb_strlen($text) > 500` validation.

---

### SEC-009: PlaceOrderAction Token Check Outside Transaction (TOCTOU)

```
ID:       SEC-009
Severity: MEDIUM
Category: Race Condition / Idempotency
Title:    Action token idempotency check is outside DB transaction
```

**Location:** `app/Modules/Orders/Actions/PlaceOrderAction.php`

**Evidence:**
```php
// OUTSIDE transaction:
$existingOrder = Order::where('action_token', $actionToken)->...->first();
if ($existingOrder) { return $existingOrder; }

// INSIDE transaction:
$order = DB::transaction(function () use (...) { ... });
```

Two concurrent requests with the same token can both pass the check. The second hits the DB UNIQUE constraint and throws `QueryException`.

**Compensating Control:** `PurchaseConversation::confirmOrder()` catches exceptions and checks for existing orders, showing "این سفارش قبلاً ثبت شده است". No financial damage occurs, but the flow is error-then-recover rather than clean idempotency.

**Recommended Fix:** Move idempotency check inside the transaction.

---

### SEC-010: Missing .gitignore, .env.example, composer.json

```
ID:       SEC-010
Severity: MEDIUM
Category: Operational Security / DevOps
Title:    Repository lacks standard Laravel project files
```

**Evidence:** No `.gitignore`, `.env.example`, or `composer.json` found in the repository root.

**Problem:**
- `.env` with secrets could be committed
- No dependency management or version pinning
- No `composer audit` for vulnerability scanning
- New developers cannot set up the project

**Recommended Fix:** Add standard Laravel `.gitignore`, `.env.example`, and `composer.json`.

---

## 7. Low Findings

### SEC-011: WalletAccount.$fillable Includes Balance Fields

```
ID:       SEC-011
Severity: LOW
Category: Mass Assignment (Defense in Depth)
```

**Location:** `app/Modules/Wallet/Models/WalletAccount.php`

`$fillable` includes `available_amount_rial`, `blocked_amount_rial`, `version`. Needed for `firstOrCreate()` in StartHandler, but could allow arbitrary balance setting if any code path passes user input to `create()`. Currently no such path exists.

---

### SEC-012: Internal DB IDs Exposed in Telegram Callback Data

```
ID:       SEC-012
Severity: LOW
Category: Information Disclosure
```

Auto-increment IDs exposed in callback_data (`cat:1`, `svc:2`, `order_detail:5`). An attacker can enumerate IDs and estimate business volume. Compensating control: order tracking validates user ownership.

---

### SEC-013: No Webhook Request Body Size Limit

```
ID:       SEC-013
Severity: LOW
Category: Denial of Service
```

No middleware limiting request body size. Compensating: Telegram limits update size, and PHP's `post_max_size` provides server-level limit.

---

### SEC-014: Action Token Stored Unencrypted in ConversationState

```
ID:       SEC-014
Severity: LOW
Category: Data Protection
```

Action token stored in plaintext in `conversation_states.data_json`. Compensating: tokens have 5-minute expiration, and DB UNIQUE constraint prevents reuse.

---

## 8. Informational Findings

### SEC-015: SmmProviderContract::getBalance() Returns float|int

```
ID:       SEC-015
Severity: INFO
Category: Type Safety
```

Provider balances use `float|int` return type. Acceptable because provider balances are in the provider's own currency and never used for internal Rial calculations.

---

### SEC-016: No Database Factories Exist

```
ID:       SEC-016
Severity: INFO
Category: Testing Infrastructure
```

No `database/factories/` directory. Model factories would improve test maintainability.

---

### SEC-017: User Model Missing declare(strict_types=1)

```
ID:       SEC-017
Severity: INFO
Category: Code Quality
```

`app/Models/User.php` is the only PHP file without `declare(strict_types=1)`.

---

## 9. Security Matrix

| ID | Severity | Category | Component | Status |
|----|----------|----------|-----------|--------|
| SEC-001 | CRITICAL | Telegram Integration | ConfirmOrderKeyboard | CONFIRMED BUG |
| SEC-002 | HIGH | Reliability / DoS | TelegramApiService | CONFIRMED |
| SEC-003 | HIGH | Rate Limiting | TelegramWebhookRateLimiter | CONFIRMED |
| SEC-004 | HIGH | Queue Safety | SubmitSmmOrderJob | CONFIRMED |
| SEC-005 | MEDIUM | Mass Assignment | Order model | CONFIRMED |
| SEC-006 | MEDIUM | DB Integrity | orders migration | CONFIRMED |
| SEC-007 | MEDIUM | Queue Safety | SendTelegramMessageJob | CONFIRMED |
| SEC-008 | MEDIUM | Input Validation | PurchaseConversation | CONFIRMED |
| SEC-009 | MEDIUM | Race Condition | PlaceOrderAction | CONFIRMED (compensated) |
| SEC-010 | MEDIUM | Operational | Repository root | CONFIRMED |
| SEC-011 | LOW | Mass Assignment | WalletAccount | CONFIRMED (no exploit path) |
| SEC-012 | LOW | Info Disclosure | Keyboards | CONFIRMED (low impact) |
| SEC-013 | LOW | DoS | Webhook | CONFIRMED (server limits) |
| SEC-014 | LOW | Data Protection | ConversationState | CONFIRMED (TTL mitigates) |
| SEC-015 | INFO | Type Safety | SmmProviderContract | OBSERVATION |
| SEC-016 | INFO | Testing | database/factories | OBSERVATION |
| SEC-017 | INFO | Code Quality | User model | OBSERVATION |

---

## 10. Financial Security Audit

| Criterion | Status | Evidence |
|-----------|--------|----------|
| **BIGINT for money** | ✅ PASS | `amount_rial BIGINT` in orders, `available_amount_rial BIGINT` in wallet_accounts, `amount_rial BIGINT` / `balance_after_rial BIGINT` in wallet_transactions, `price_per_1000_rial BIGINT` in services |
| **Integer arithmetic** | ✅ PASS | `PriceCalculator` uses `intdiv()`. `Money::toToman()` uses `intdiv()`. No `(float)/(double)` casts. No float division. |
| **Wallet locking** | ✅ PASS | `DebitWalletAction` and `CreditWalletAction` both use `lockForUpdate()` inside `DB::transaction()` |
| **Append-only protection** | ✅ PASS | `WalletTransaction` and `OrderEvent` throw `LogicException` on `update()`, `delete()`, `destroy()`, `forceDelete()` |
| **Idempotency keys** | ✅ PASS | `wallet_transactions.idempotency_key` UNIQUE. Debit: `"order-debit:{id}"`. Refund: `"order-refund:{id}"` |
| **Refund safety** | ✅ PASS | `RefundOrderAction` uses `CreditWalletAction` with idempotency key. `lockForUpdate()` on both order and wallet |
| **Concurrency safety** | ✅ PASS | `lockForUpdate()` prevents double-spending. DB UNIQUE on `idempotency_key` prevents duplicate credits/debits |
| **No direct balance mutation** | ✅ PASS | No `->increment()`, `->decrement()`, or raw UPDATE on balance fields |

---

## 11. Telegram Security Audit

| Criterion | Status | Notes |
|-----------|--------|-------|
| **Webhook secret validation** | ✅ PASS | `hash_equals()` for timing-safe comparison |
| **Safe error responses** | ✅ PASS | JSON only. No stack traces |
| **Update validation** | ✅ PASS | Requires numeric `update_id` |
| **Replay protection** | ✅ PASS | `TelegramUpdateIdTracker` with atomic `Cache::add()` |
| **Callback security** | ❌ SEC-001 | Token exceeds 64-byte limit |
| **Action Token crypto** | ✅ PASS | `random_bytes(32)` = 256 bits entropy |
| **Action Token single-use** | ✅ PASS | DB UNIQUE on `orders.action_token` |
| **Action Token ownership** | ✅ PASS | Checked via `user_id` and conversation state |
| **Rate limiting** | ⚠️ SEC-003 | Per-IP is wrong for webhooks |
| **Authorization** | ✅ PASS | `forUser($userId)` scope on all queries |

---

## 12. SMM Provider Audit

| Criterion | Status | Notes |
|-----------|--------|-------|
| **Contract abstraction** | ✅ PASS | `SmmProviderContract` interface |
| **Factory pattern** | ✅ PASS | `SmmProviderFactory::make()` |
| **Secret handling** | ✅ PASS | `$hidden`, `Crypt::encryptString()` |
| **Provider isolation** | ✅ PASS | Job resolves via factory |
| **Typed DTOs** | ✅ PASS | `readonly` DTOs |
| **No webhook blocking** | ✅ PASS | Provider calls in jobs only |
| **Job idempotency** | ✅ PASS | Status check before provider call |

---

## 13. Database Integrity Audit

| Table | PK | FK | Unique | Indexes | Money Type | Append-Only |
|-------|----|----|--------|---------|------------|-------------|
| users | ✅ | — | `telegram_id` ✅ | — | — | — |
| wallet_accounts | ✅ | `user_id` ✅ | `user_id` ✅ | — | BIGINT ✅ | — |
| wallet_transactions | ✅ | `wallet_id` ✅ | `idempotency_key` ✅ | ✅ | BIGINT ✅ | ✅ |
| orders | ✅ | `user_id` ✅, `service_id` ⚠️ | `order_code` ✅, `action_token` ✅ | ✅ | BIGINT ✅ | — |
| order_events | ✅ | `order_id` ✅ | — | ✅ | — | ✅ |
| categories | ✅ | `parent_id` ✅ | `code` ✅ | ✅ | — | — |
| services | ✅ | `category_id` ✅, `provider_id` ✅ | `[provider_id,provider_service_id]` ✅ | ✅ | BIGINT ✅ | — |
| smm_providers | ✅ | — | `code` ✅ | — | BIGINT ✅ | — |
| conversation_states | ✅ | `user_id` ✅ | `user_id` ✅ | — | — | — |

**Key Issue:** `orders.service_id` has no foreign key constraint (SEC-006).

---

## 14. Test Coverage Audit

### Adequately Covered
- ✅ Money value object arithmetic (16 tests)
- ✅ Order status transitions (16 assertions)
- ✅ Wallet debit/credit with concurrency (11 tests)
- ✅ Wallet transaction immutability
- ✅ PlaceOrderAction (7 tests)
- ✅ Idempotent order creation
- ✅ SubmitSmmOrderJob lifecycle (10 tests)
- ✅ SubmitSmmOrderJob failure/retry (10 tests)
- ✅ Webhook security (11 tests)
- ✅ PriceCalculator boundary conditions (14 tests)

### Missing / Weak Tests

| Area | Issue |
|------|-------|
| **callback_data size** | No test verifies ≤64-byte callback_data (would catch SEC-001) |
| **HTTP timeout behavior** | No test for slow API responses |
| **End-to-end purchase** | Tests mock `SendTelegramMessageJob` — never verify actual Telegram API calls succeed |
| **Rate limiter correctness** | Tests verify 429 but not per-user vs per-IP semantics |
| **Input length limits** | No test for oversized dynamic inputs |
| **Provider hang** | No test for job timeout when provider hangs |
| **Concurrent confirmation** | No test for simultaneous `PlaceOrderAction` with same token |
| **RefundOrderAction** | No standalone test file |
| **Database factories** | None exist for any model |

---

## 15. Architecture Findings

| Area | Assessment |
|------|-----------|
| **Modular Monolith** | ✅ PASS — All domain logic under `app/Modules/`. Controllers thin. |
| **Wallet centralization** | ✅ PASS — All mutations through `CreditWalletAction`/`DebitWalletAction`. |
| **Provider isolation** | ✅ PASS — Contract → Factory → Adapter. No direct coupling. |
| **DTO typing** | ✅ PASS — `readonly` DTOs for provider results. |
| **Job orchestration** | ✅ PASS — SMM in jobs, messages as jobs, no blocking in webhook. |
| **Dependency direction** | ✅ PASS — No circular dependencies. |
| **God classes** | ⚠️ MINOR — `PurchaseConversation` (399 lines) handles all purchase states. |

---

## 16. Production Readiness Checklist

| Check | Status |
|-------|--------|
| Purchase flow works end-to-end | ❌ FAIL (SEC-001) |
| Webhook security | ✅ PASS |
| Financial integrity | ✅ PASS |
| Wallet concurrency | ✅ PASS |
| Order state machine | ✅ PASS |
| Idempotency | ✅ PASS |
| Append-only ledgers | ✅ PASS |
| Secret management | ✅ PASS |
| HTTP timeouts | ❌ FAIL (SEC-002) |
| Rate limiting correctness | ❌ FAIL (SEC-003) |
| Job timeout | ❌ FAIL (SEC-004) |
| .gitignore present | ❌ FAIL (SEC-010) |
| .env.example present | ❌ FAIL (SEC-010) |
| composer.json present | ❌ FAIL (SEC-010) |
| Test suite passing | ⬜ UNKNOWN (no vendor/) |
| migrate:fresh --seed | ⬜ UNKNOWN (no framework) |

---

## 17. Recommended Fix Order

### Priority 1: CRITICAL
1. **SEC-001** — Fix callback_data size (shorten token or use alternative confirmation)

### Priority 2: HIGH
2. **SEC-002** — Add HTTP timeouts to TelegramApiService
3. **SEC-004** — Add `$timeout` to SubmitSmmOrderJob
4. **SEC-003** — Fix rate limiter to per-user or remove IP-based limiting

### Priority 3: MEDIUM
5. **SEC-010** — Add .gitignore, .env.example, composer.json
6. **SEC-005** — Narrow Order.$fillable or switch to $guarded
7. **SEC-007** — Add retry config to SendTelegramMessageJob
8. **SEC-008** — Add input length validation
9. **SEC-006** — Add FK constraint for orders.service_id
10. **SEC-009** — Move token check inside transaction

### Priority 4: LOW
11. **SEC-011** — Review WalletAccount.$fillable
12. **SEC-012** — Consider UUIDs for public-facing IDs
13. **SEC-013** — Add request size middleware
14. **SEC-014** — Consider hashing token in conversation state

---

## 18. Final Verdict

```
VERDICT: NOT READY
```

**Reason:** SEC-001 (CRITICAL) completely blocks the purchase confirmation flow. The 72-byte `callback_data` exceeds Telegram's 64-byte limit, making it impossible for users to confirm orders.

Once SEC-001 is fixed, the project achieves **READY WITH CONDITIONS** status — the financial infrastructure is sound, but SEC-002/003/004 should be resolved before handling real traffic at scale.

---

*No application code was modified during this audit.*
*All findings are based on actual code inspection, not assumptions.*
*No secrets or credential values appear in this report.*
