# Phase 7 — cPanel Production Patches

> **Purpose:** This document amends Phases 1–6 with 5 critical patches required for reliable operation on **shared cPanel hosting**. Each patch specifies the exact file, section, or task it overrides. Developers must apply these amendments when implementing the referenced phase.

---

## Patch Index

| # | Patch | Affects Phase(s) | Risk if Ignored |
|---|---|---|---|
| P1 | [Optimize cPanel Queue Worker](#patch-1--optimize-cpanel-queue-worker) | Phase 6 | Broadcast and bulk jobs process one item per minute — hours of delay for 1000+ users |
| P2 | [Prevent Database Bloat](#patch-2--prevent-database-bloat) | Phase 3, Phase 6 | `smm_connection_logs` and `audit_logs` exceed cPanel disk quota within weeks |
| P3 | [Fix Partial Refund Calculation](#patch-3--fix-partial-refund-calculation) | Phase 3 | Financial discrepancy: refund based on current price instead of purchase-time price |
| P4 | [Prevent Telegram Rate Limit Clashes](#patch-4--prevent-telegram-rate-limit-clashes) | Phase 5 | Bot returns 429 errors to organic users during broadcasts — order/payment flows break |
| P5 | [Enforce SMM Provider Timeout Cap](#patch-5--enforce-smm-provider-timeout-cap) | Phase 3 | Hanging HTTP request freezes queue worker for its entire cron window — all jobs stall |

---

## Patch 1 — Optimize cPanel Queue Worker

**Severity:** 🔴 Critical  
**Patches:** Phase 6 §4.4 (cPanel Deployment Configuration), Phase 6 §5.2 (Scheduler)

### Problem

Phase 6 specifies the following cPanel cron entry:

```
* * * * * /usr/local/bin/php /home/ACCOUNT/smm-bot-app/artisan queue:work database --once --tries=3 --timeout=60
```

The `--once` flag processes **exactly one job** per cron invocation. On cPanel, cron runs at most once per minute. This means:

- A broadcast to 5,000 users (100 batch jobs of 50) takes **100 minutes minimum**.
- If `SyncOrderStatusJob`, `ExpireInvoicesJob`, and `VerifyPaymentJob` are also queued, they compete for the same one-job-per-minute slot.
- During high load, payment verifications and order submissions back up critically.

### Amendment

**Override the cron entry in Phase 6 §4.4 with:**

```
* * * * * /usr/local/bin/php /home/ACCOUNT/smm-bot-app/artisan queue:work database --max-time=55 --stop-when-empty --tries=3 --timeout=15 >> /home/ACCOUNT/logs/queue.log 2>&1
```

| Flag | Purpose |
|---|---|
| `--max-time=55` | Worker processes as many jobs as possible for 55 seconds, then gracefully exits before the next cron invocation at 60 seconds. Prevents overlapping workers. |
| `--stop-when-empty` | If the queue empties before 55 seconds, exit immediately — frees PHP process memory on the shared host. |
| `--tries=3` | Unchanged — max 3 attempts per job before marking as failed. |
| `--timeout=15` | **Reduced from 60 to 15 seconds.** A single hanging job must not consume the entire 55-second window. Aligns with Patch 5 (SMM timeout cap). |

### Checklist

- [ ] **Override** Phase 6 §4.4 cron entry for `queue:work` with the command above
- [ ] **Override** Phase 6 §5.2 cron reference to match
- [ ] **Update** Phase 6 `DEPLOYMENT_CPANEL.md` deliverable to document the `--max-time=55` rationale
- [ ] **Update** `.env.example` to add `QUEUE_WORKER_MAX_TIME=55` for configurability
- [ ] **Verify** no second cron entry spawns an overlapping worker (only one `queue:work` cron line)
- [ ] **Test** with 200+ queued jobs: confirm all processed within ~4 cron cycles (4 minutes), not 200 minutes

---

## Patch 2 — Prevent Database Bloat

**Severity:** 🟠 High  
**Patches:** Phase 3 §3.1 (`smm_connection_logs`), Phase 1 §3.10 (`audit_logs`), Phase 6 §5.2 (Scheduler)

### Problem

On shared cPanel hosting, MySQL databases typically have a **1–2 GB storage quota**. Two tables will grow unbounded:

| Table | Growth Rate (Estimated) | 30-Day Projection |
|---|---|---|
| `smm_connection_logs` | ~50 rows/hour (status sync every 3 min × active orders + health checks) | ~36,000 rows → ~15–30 MB |
| `audit_logs` | ~20 rows/hour (admin actions, wallet ops, order events) | ~14,400 rows → ~5–10 MB |

At scale (1000+ daily orders), these tables grow 10x faster. Combined with `payment_callbacks`, `order_events`, `broadcast_deliveries`, and `cron_runs`, the database can exceed quota within 2–3 months, causing **all writes to fail** — including order placement and payment processing.

### Amendment

#### 2a. Add `Prunable` Trait to High-Growth Models

**Add to Phase 3 tasks (§4 Actionable Tasks) — new task after §4.5:**

```php
// app/Modules/Smm/Models/SmmConnectionLog.php
use Illuminate\Database\Eloquent\Prunable;

class SmmConnectionLog extends Model
{
    use Prunable;

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(30));
    }
}
```

```php
// app/Modules/Shared/Models/AuditLog.php
use Illuminate\Database\Eloquent\Prunable;

class AuditLog extends Model
{
    use Prunable;

    public function prunable(): Builder
    {
        // Retain financial audit logs for 90 days; others for 30 days
        return static::where(function ($query) {
            $query->where('action', 'NOT LIKE', 'wallet.%')
                  ->where('action', 'NOT LIKE', 'invoice.%')
                  ->where('created_at', '<', now()->subDays(30));
        })->orWhere(function ($query) {
            $query->where(function ($q) {
                $q->where('action', 'LIKE', 'wallet.%')
                  ->orWhere('action', 'LIKE', 'invoice.%');
            })->where('created_at', '<', now()->subDays(90));
        });
    }
}
```

**Also apply `Prunable` to these auxiliary models (add to their respective phases):**

| Model | Phase | Retention |
|---|---|---|
| `SmmConnectionLog` | Phase 3 | 30 days |
| `AuditLog` | Phase 1 (financial: 90 days, other: 30 days) |
| `PaymentCallback` | Phase 2 | 60 days |
| `BroadcastDelivery` | Phase 5 | 30 days (after broadcast `completed`) |
| `CronRun` | Phase 6 | 14 days |
| `OtpVerification` | Phase 2 | 7 days |
| `ConversationState` | Phase 1 | Already TTL-based; add prunable for safety (1 day past `expires_at`) |

#### 2b. Register Daily Pruning in Scheduler

**Add to Phase 6 §5.2 (Scheduler — Final):**

```php
Schedule::command('model:prune')->daily()->at('02:00')->timezone('Asia/Tehran');
```

### Checklist

- [ ] **Add** `Prunable` trait + `prunable()` method to `SmmConnectionLog` (Phase 3)
- [ ] **Add** `Prunable` trait + `prunable()` method to `AuditLog` with tiered retention (Phase 1)
- [ ] **Add** `Prunable` trait to `PaymentCallback` — 60-day retention (Phase 2)
- [ ] **Add** `Prunable` trait to `BroadcastDelivery` — 30-day retention post-completion (Phase 5)
- [ ] **Add** `Prunable` trait to `CronRun` — 14-day retention (Phase 6)
- [ ] **Add** `Prunable` trait to `OtpVerification` — 7-day retention (Phase 2)
- [ ] **Add** `Prunable` trait to `ConversationState` — 1 day past `expires_at` (Phase 1)
- [ ] **Add** `Schedule::command('model:prune')->daily()->at('02:00')` to Phase 6 §5.2 scheduler
- [ ] **Add** cPanel cron documentation: `model:prune` runs via `schedule:run`, no separate cron entry needed
- [ ] **Add** acceptance criterion to Phase 6 §7: "After 35 days, `smm_connection_logs` contains no rows older than 30 days"
- [ ] **Test** pruning on a populated database: verify only records beyond retention are deleted; recent records untouched

---

## Patch 3 — Fix Partial Refund Calculation

**Severity:** 🔴 Critical (Financial Integrity)  
**Patches:** Phase 3 §4.7 (`ProcessPartialOrderAction`)

### Problem

Phase 3 §4.7 states:

> *"Calculate refund: `remains * price_per_1000 / 1000` (integer arithmetic)"*

This implicitly uses the **current live** `services.price_per_1000_rial`, which may have changed since the order was placed. If an admin raises the price from 50,000 to 80,000 Rial/1000 after the order, the refund would be calculated at the new higher price — **over-refunding** the user and creating a financial loss.

Conversely, if the price dropped, the user would be **under-refunded**.

### Amendment

**Override Phase 3 §4.7 `ProcessPartialOrderAction` step 1 with:**

```
1. If provider reports `partial` with `remains` count:
   a. Extract the HISTORICAL price from `orders.snapshot_json → price_per_1000_rial`
      — this is the price frozen at order creation time (Phase 1 §4.8, PlaceOrderAction step 4).
   b. Calculate refund amount:
      refund_rial = (int) ceil(remains * snapshot_price_per_1000_rial / 1000)
   c. Validate: refund_rial <= orders.amount_rial (cannot refund more than charged)
   d. Call `RefundOrderAction` with the calculated partial amount
   e. Transition order to `partial`
   f. Notify user with: delivered count, refunded amount (Toman), original quantity
```

**Explicitly NEVER use:**
- `services.price_per_1000_rial` (current live price — may have changed)
- `services.cost_per_1000_rial` (buy cost — irrelevant to user refund)
- Any discounted/promotional price not captured in the snapshot

### Checklist

- [ ] **Override** Phase 3 §4.7 step 1 refund calculation to use `orders.snapshot_json.price_per_1000_rial`
- [ ] **Verify** Phase 1 §4.8 `PlaceOrderAction` stores `price_per_1000_rial` in `snapshot_json` — already specified, confirm implementation
- [ ] **Add** guard clause: `assert(refund_rial <= order.amount_rial)` — prevent over-refund
- [ ] **Add** unit test: `PartialRefundSnapshotPriceTest`:
  - Create order at price 50,000/1000
  - Change service price to 80,000/1000
  - Provider returns `partial` with `remains=500` out of `quantity=1000`
  - Assert refund = `ceil(500 * 50000 / 1000)` = **25,000 Rial** (NOT 40,000)
- [ ] **Add** unit test: `PartialRefundOverRefundGuardTest`:
  - Ensure refund amount never exceeds `orders.amount_rial`
- [ ] **Update** Phase 3 acceptance criteria AC-9 to explicitly state: "uses snapshot price, not current price"

---

## Patch 4 — Prevent Telegram Rate Limit Clashes

**Severity:** 🟠 High  
**Patches:** Phase 5 §4.3 (`SendBroadcastBatchJob`), Phase 5 §5.1 (Jobs table)

### Problem

Phase 5 §4.3 states:

> *"Rate limiting: max 30 messages/second (Telegram limit)"*

The official Telegram Bot API limit is ~30 messages/second, but this is the **absolute ceiling** for the entire bot — not just broadcasts. During a broadcast:

- Organic user interactions (order confirmations, payment notifications, menu responses, OTP messages) also consume this budget.
- At 30 msg/sec broadcast rate, any organic message triggers a **429 Too Many Requests** error from Telegram.
- Failed organic messages mean: order confirmations not delivered, payment success messages lost, OTP codes undeliverable.
- Telegram may temporarily block the bot entirely if 429 errors persist.

### Amendment

**Override Phase 5 §4.3 broadcast rate limit:**

| Parameter | Phase 5 (Original) | Patch 4 (Override) | Rationale |
|---|---|---|---|
| Max broadcast rate | 30 msg/sec | **20–25 msg/sec** (configurable via `BROADCAST_RATE_LIMIT` env) | Reserves 5–10 msg/sec for organic traffic |
| Batch size | 50 users per job | **25–30 users per job** | Smaller batches = more granular pause/resume + less memory per job on shared host |
| Inter-message delay | None specified | **40–50 ms between messages** within a batch | Smooths out burst patterns that trigger Telegram rate limiting |
| Backoff on 429 | "release job with delay" | **Release with `retry_after` value from Telegram response + 5 seconds buffer** | Telegram's 429 includes a `retry_after` field — respect it exactly |

**Implementation in `SendBroadcastBatchJob`:**

```php
// Config: default 25 msg/sec, overridable
$rateLimit = (int) config('broadcast.rate_limit', 25);
$delayMs = (int) ceil(1000 / $rateLimit); // 40ms at 25/sec

foreach ($userBatch as $user) {
    try {
        // Send or forward message...
        usleep($delayMs * 1000); // microseconds
    } catch (TelegramRateLimitException $e) {
        // Telegram returned 429 with retry_after
        $retryAfter = $e->retryAfter + 5; // extra buffer
        $this->release($retryAfter);
        return; // Exit job, will be retried
    }
}
```

**Add to `.env.example`:**

```env
BROADCAST_RATE_LIMIT=25
BROADCAST_BATCH_SIZE=25
```

### Checklist

- [ ] **Override** Phase 5 §4.3 rate limit from 30 msg/sec to **25 msg/sec** (configurable)
- [ ] **Override** Phase 5 §4.3 batch size from 50 to **25 users per job** (configurable)
- [ ] **Add** inter-message delay: `usleep()` of `ceil(1000 / rate_limit)` milliseconds between sends
- [ ] **Add** 429 handling: parse `retry_after` from Telegram response, release job with `retry_after + 5` seconds
- [ ] **Add** `BROADCAST_RATE_LIMIT` and `BROADCAST_BATCH_SIZE` to `.env.example` and `config/broadcast.php`
- [ ] **Update** Phase 5 §5.1 Jobs table: batch size = 25, rate = 25 msg/sec
- [ ] **Add** acceptance test: send broadcast during simulated organic traffic → organic messages delivered without 429
- [ ] **Update** Phase 5 AC-7 to state: "broadcast rate does not exceed 25 msg/sec; organic messages unaffected"

---

## Patch 5 — Enforce SMM Provider Timeout Cap

**Severity:** 🔴 Critical  
**Patches:** Phase 3 §4.2 (V1 Adapter), Phase 3 §4.3 (V2 Adapter), Phase 3 §4.4 (`HandlesHttpRequests`), Phase 1 §3.6 (`smm_providers` defaults)

### Problem

Phase 3 §4.4 (`HandlesHttpRequests`) specifies:

> *"Configurable `connect_timeout` and `total_timeout` from provider settings"*

And Phase 1 §3.6 sets `smm_providers.timeout_seconds` default to **30 seconds**.

On a shared cPanel host with `queue:work --max-time=55`:

- A single order submission hanging for 30 seconds consumes **54%** of the worker's 55-second window.
- If the job retries (3 attempts × 30 seconds = 90 seconds), it blocks the queue for nearly **two full cron cycles**.
- During this time, payment verifications, broadcasts, and other order submissions are completely stalled.
- If the provider is genuinely down, the worker repeatedly wastes its entire time budget on timeouts.

### Amendment

**Hard cap all SMM provider HTTP timeouts:**

| Parameter | Phase 3 (Original) | Patch 5 (Override) | Rationale |
|---|---|---|---|
| `connect_timeout` | Configurable, no cap | **5 seconds hard cap** | If DNS/TCP doesn't connect in 5s on shared hosting, it won't connect at all |
| `total_timeout` (request) | 30s default, configurable | **10–15 seconds hard cap** | Must leave room for retry + other jobs within the 55-second worker window |
| Retry backoff | `2^attempts * 30` seconds | **`2^attempts * 10` seconds** (10s, 20s, 40s) | Faster retry cycle; don't waste worker time |
| Timeout behavior | "retriable" | **Immediate release with short delay** | Don't let the job sit in `submitting` state; release instantly for next cron cycle |

**Override in `HandlesHttpRequests` trait (Phase 3 §4.4):**

```php
private function buildHttpClient(SmmProvider $provider): PendingRequest
{
    $connectTimeout = min($provider->connect_timeout ?? 5, 5);    // HARD CAP: 5s
    $totalTimeout   = min($provider->timeout_seconds ?? 15, 15);  // HARD CAP: 15s

    return Http::timeout($totalTimeout)
               ->connectTimeout($connectTimeout)
               ->withOptions([
                   'http_errors' => false,
                   'allow_redirects' => false,   // SSRF: no redirects on POST
               ]);
}
```

**Override `smm_providers.timeout_seconds` default:**

```diff
// Phase 1 §3.6 — smm_providers table
- | `timeout_seconds` | `INT UNSIGNED` | `NOT NULL DEFAULT 30` |
+ | `timeout_seconds` | `INT UNSIGNED` | `NOT NULL DEFAULT 15` |
```

**Override in `SubmitSmmOrderJob` (Phase 3 §4.6) — timeout handling:**

```php
// On ProviderTimeoutException:
Log::warning('SMM provider timeout', ['provider' => $provider->code, 'order' => $order->order_code]);
$this->release(2 ** $this->attempts() * 10); // 10s, 20s, 40s — NOT 30s, 60s, 120s
return; // Exit immediately — don't block the worker
```

**Override in `AddProviderConversation` (Phase 4 §4.5):**

When admin enters timeout, validate:

```php
$timeout = (int) $input;
if ($timeout > 15) {
    return $this->reply("⚠️ حداکثر زمان timeout در هاست اشتراکی ۱۵ ثانیه است. مقدار وارد شده اصلاح شد.");
    $timeout = 15;
}
```

### Checklist

- [ ] **Override** Phase 1 §3.6 `smm_providers.timeout_seconds` default from `30` to `15`
- [ ] **Override** Phase 3 §4.4 `HandlesHttpRequests`: add hard caps — `connect_timeout ≤ 5s`, `total_timeout ≤ 15s`
- [ ] **Override** Phase 3 §4.4 `HandlesHttpRequests`: enforce `allow_redirects => false` for all POST requests
- [ ] **Override** Phase 3 §4.6 `SubmitSmmOrderJob` retry backoff from `2^n * 30` to `2^n * 10` seconds
- [ ] **Override** Phase 3 §4.6 `SubmitSmmOrderJob` timeout handling: release job immediately, do not block worker
- [ ] **Override** Phase 4 §4.5 `AddProviderConversation`: validate timeout ≤ 15s, auto-correct if exceeded
- [ ] **Add** validation in `SmmProvider` model: `timeout_seconds` attribute mutator caps at 15
- [ ] **Update** Phase 3 AC-6 to state: "timeout error → job released within 1 second (no blocking), retried with backoff of 10s, 20s, 40s"
- [ ] **Add** test: `ProviderTimeoutCapTest` — setting `timeout_seconds=60` on provider → actual Guzzle timeout is 15s
- [ ] **Add** test: `TimeoutJobReleaseTest` — provider times out → job released immediately, next job in queue processed within same cron cycle

---

## Summary of All Overrides

The table below maps every override to its original location for quick developer reference.

| Patch | Original Phase | Original Section | What Changes |
|---|---|---|---|
| P1 | Phase 6 | §4.4 Cron entry | `--once` → `--max-time=55 --stop-when-empty`; `--timeout=60` → `--timeout=15` |
| P1 | Phase 6 | §5.2 Scheduler | Cron reference updated |
| P2 | Phase 3 | §4 (new task) | `SmmConnectionLog` gets `Prunable` (30 days) |
| P2 | Phase 1 | §3.10 / model | `AuditLog` gets `Prunable` (30/90 days tiered) |
| P2 | Phase 2 | Models | `PaymentCallback` (60 days), `OtpVerification` (7 days) get `Prunable` |
| P2 | Phase 5 | Models | `BroadcastDelivery` gets `Prunable` (30 days post-completion) |
| P2 | Phase 6 | §5.2 Scheduler | Add `model:prune` daily at 02:00 |
| P3 | Phase 3 | §4.7 step 1 | Refund uses `snapshot_json.price_per_1000_rial`, NOT live price |
| P3 | Phase 3 | AC-9 | Clarify: "uses snapshot price" |
| P4 | Phase 5 | §4.3 Rate limit | 30 msg/sec → 25 msg/sec (configurable) |
| P4 | Phase 5 | §4.3 Batch size | 50 → 25 users/job (configurable) |
| P4 | Phase 5 | §4.3 429 handling | Parse `retry_after`, release with buffer |
| P4 | Phase 5 | §5.1 Jobs table | Updated rate + batch parameters |
| P5 | Phase 1 | §3.6 `smm_providers` | `timeout_seconds` default 30 → 15 |
| P5 | Phase 3 | §4.4 `HandlesHttpRequests` | Hard cap: connect 5s, total 15s |
| P5 | Phase 3 | §4.6 `SubmitSmmOrderJob` | Backoff 30s base → 10s base; immediate release on timeout |
| P5 | Phase 4 | §4.5 `AddProviderConversation` | Validate timeout ≤ 15s |

---

## Updated Scheduler (Final — All Phases + Patches)

Replaces Phase 6 §5.2 entirely:

```php
// Phase 1
Schedule::job(new CleanExpiredConversationsJob)->hourly();

// Phase 2
Schedule::job(new ExpireInvoicesJob)->everyFiveMinutes();

// Phase 3
Schedule::job(new SyncOrderStatusJob)->everyThreeMinutes();
Schedule::job(new ProviderHealthCheckJob)->everyFifteenMinutes();

// Phase 5
Schedule::job(new DailyMetricsSnapshotJob)
    ->dailyAt('00:05')
    ->timezone('Asia/Tehran');

// Phase 6
Schedule::command('cron:verify')->everyTenMinutes();

// Phase 7 — Patch P2
Schedule::command('model:prune')->daily()->at('02:00')->timezone('Asia/Tehran');
```

## Updated cPanel Cron Entries (Final)

Replaces Phase 6 §4.4 cron entries entirely:

```cron
* * * * * /usr/local/bin/php /home/ACCOUNT/smm-bot-app/artisan schedule:run >> /home/ACCOUNT/logs/scheduler.log 2>&1
* * * * * /usr/local/bin/php /home/ACCOUNT/smm-bot-app/artisan queue:work database --max-time=55 --stop-when-empty --tries=3 --timeout=15 >> /home/ACCOUNT/logs/queue.log 2>&1
```
