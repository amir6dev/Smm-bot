# Phase 5 — Marketing & Operations: Referrals, Gifts, Broadcasts & Metrics

---

## 1. Phase Goal

Deliver the **marketing and operational tooling** including the referral/affiliate system, configurable gift rules, queue-backed broadcast messaging, and comprehensive bot statistics. By the end of this phase:

| Capability | Detail |
|---|---|
| Referral system | Deep-link generation, one-time referrer assignment, anti-fraud checks |
| Referral rewards | Configurable: welcome gift, referrer bonus on sign-up, commission on charge/purchase |
| Gift rules engine | Admin-configurable rules: event type, amount/percent, daily/total caps, validity period |
| Queue-backed broadcasts | Admin sends message/forward → preview → confirm → batched queue delivery |
| Broadcast management | Progress tracking, pause/resume, blocked user detection, delivery stats |
| Bot statistics | Full dashboard: users, revenue, orders, invoices, registrations, cron health |
| Daily metrics snapshot | Pre-computed daily aggregates to avoid heavy real-time queries |
| Ops channel notifications | Structured alerts for key business events |
| "زیرمجموعه‌گیری" user flow | Banner + referral link + stats display |
| "قیمت سرویس‌ها" user flow | Category → service price list (per 1000 units) |

> **Architectural Constraints enforced in this phase:**
> - All reward/gift amounts stored as **BIGINT Rial**. Toman display only.
> - Gift credits use the **append-only wallet ledger**. No direct balance edits.
> - Referral assignment is **one-time and immutable**. Self-referral and circular references forbidden.
> - Broadcasts use **Queue Jobs** with rate limiting. Never inline in webhook response.
> - Blocked user detection: `Forbidden` from Telegram → mark user as `is_blocked_bot=true` — **do not delete** user.

---

## 2. Modules & Architecture

### 2.1 Directory Structure Affected

```
app/Modules/
├── Referrals/
│   ├── Models/
│   │   ├── Referral.php
│   │   └── ReferralReward.php
│   ├── Enums/
│   │   ├── ReferralEvent.php
│   │   └── RewardStatus.php
│   ├── Actions/
│   │   ├── AssignReferrerAction.php
│   │   ├── ProcessReferralRewardAction.php
│   │   └── GenerateReferralLinkAction.php
│   ├── Services/
│   │   ├── ReferralValidationService.php
│   │   └── ReferralQueryService.php
│   └── Providers/
│       └── ReferralServiceProvider.php
│
├── Gifts/
│   ├── Models/
│   │   └── GiftRule.php
│   ├── Enums/
│   │   └── GiftEventType.php
│   ├── Actions/
│   │   └── ApplyGiftAction.php
│   ├── Services/
│   │   └── GiftRuleEngine.php
│   └── Providers/
│       └── GiftServiceProvider.php
│
├── Broadcast/
│   ├── Models/
│   │   ├── Broadcast.php
│   │   └── BroadcastDelivery.php
│   ├── Enums/
│   │   ├── BroadcastStatus.php
│   │   └── DeliveryStatus.php
│   ├── Actions/
│   │   ├── CreateBroadcastAction.php
│   │   ├── PauseBroadcastAction.php
│   │   └── ResumeBroadcastAction.php
│   ├── Services/
│   │   └── BroadcastQueryService.php
│   └── Providers/
│       └── BroadcastServiceProvider.php
│
├── Telegram/
│   ├── Handlers/
│   │   ├── ReferralHandler.php                # NEW: "زیرمجموعه‌گیری"
│   │   ├── PriceListHandler.php               # NEW: "قیمت سرویس‌ها"
│   │   ├── Admin/
│   │   │   ├── AdminBroadcastHandler.php      # Phase 4 stub → implemented
│   │   │   ├── AdminStatsHandler.php          # Phase 4 stub → implemented
│   │   │   └── AdminGiftHandler.php           # Phase 4 stub → implemented
│   │   └── StartHandler.php                   # Extended: referral + welcome gift
│   ├── Conversations/
│   │   ├── Admin/
│   │   │   ├── BroadcastConversation.php      # NEW
│   │   │   ├── CreateGiftRuleConversation.php # NEW
│   │   │   └── EditGiftRuleConversation.php   # NEW
│   │   └── ...
│   └── Keyboards/
│       ├── ReferralKeyboard.php               # NEW
│       └── Admin/
│           ├── BroadcastMenuKeyboard.php       # NEW
│           ├── StatsMenuKeyboard.php           # NEW
│           └── GiftRuleListKeyboard.php        # NEW

app/Jobs/
├── SendBroadcastBatchJob.php                  # NEW
├── DailyMetricsSnapshotJob.php                # NEW
└── NotifyOpsChannelJob.php                    # Extended

database/migrations/
├── 0026_create_referrals_table.php
├── 0027_create_referral_rewards_table.php
├── 0028_create_gift_rules_table.php
├── 0029_create_broadcasts_table.php
├── 0030_create_broadcast_deliveries_table.php
├── 0031_create_daily_metrics_table.php
└── 0032_add_is_blocked_bot_to_users.php

database/seeders/
└── GiftRuleSeeder.php
```

### 2.2 Module Dependency Graph (Phase 5)

```
Referrals → Wallet (credit rewards) → Shared (audit)
Gifts → Wallet (credit gifts) → Shared (audit)
Broadcast → Telegram (send messages)
StartHandler → Referrals (assign referrer) → Gifts (welcome gift)
Orders → Referrals (trigger purchase reward)
Payments → Referrals (trigger charge reward)
AdminStatsHandler → Orders + Payments + Wallet + Users (aggregate queries)
DailyMetricsSnapshotJob → all modules (daily aggregation)
```

---

## 3. Database Schema

### 3.1 `referrals`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `referrer_user_id` | `BIGINT UNSIGNED` | `NOT NULL FK → users.id` | The user who shared the link |
| `referred_user_id` | `BIGINT UNSIGNED` | `UNIQUE NOT NULL FK → users.id` | **UNIQUE — each user has at most one referrer** |
| `referral_token` | `VARCHAR(20)` | `NOT NULL` | Short random token used in deep link |
| `is_valid` | `BOOLEAN` | `NOT NULL DEFAULT TRUE` | Anti-fraud flag |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `UNIQUE idx_ref_referred (referred_user_id)`, `idx_ref_referrer (referrer_user_id, created_at)`, `UNIQUE idx_ref_token (referral_token)`

**Constraints:**
- `referred_user_id != referrer_user_id` (CHECK constraint or application-level)
- Once set, `referrer_user_id` is **immutable** — cannot be changed later.

### 3.2 `referral_rewards`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `referral_id` | `BIGINT UNSIGNED` | `NOT NULL FK → referrals.id` | |
| `beneficiary_user_id` | `BIGINT UNSIGNED` | `NOT NULL FK → users.id` | Who receives the reward (referrer or referred) |
| `event` | `VARCHAR(30)` | `NOT NULL` | `signup`, `first_charge`, `charge`, `purchase` |
| `trigger_reference_type` | `VARCHAR(50)` | `NULLABLE` | `invoice`, `order` |
| `trigger_reference_id` | `BIGINT UNSIGNED` | `NULLABLE` | FK to triggering entity |
| `amount_rial` | `BIGINT` | `NOT NULL` | **BIGINT — reward amount in Rial** |
| `wallet_transaction_id` | `BIGINT UNSIGNED` | `NULLABLE FK → wallet_transactions.id` | Link to ledger entry |
| `status` | `VARCHAR(20)` | `NOT NULL DEFAULT 'pending'` | `pending`, `credited`, `rejected` |
| `reason` | `VARCHAR(500)` | `NULLABLE` | Anti-fraud rejection reason |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `idx_rr_referral (referral_id)`, `idx_rr_beneficiary (beneficiary_user_id, event, created_at)`, `UNIQUE idx_rr_idempotency (referral_id, event, trigger_reference_type, trigger_reference_id)`

### 3.3 `gift_rules`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `event_type` | `VARCHAR(30)` | `NOT NULL` | `signup`, `referrer_signup`, `referrer_charge`, `referrer_purchase` |
| `name` | `VARCHAR(255)` | `NOT NULL` | Display name |
| `amount_type` | `VARCHAR(10)` | `NOT NULL` | `fixed` or `percent` |
| `amount_value` | `BIGINT` | `NOT NULL` | Fixed: Rial amount. Percent: basis points (e.g., 500 = 5%) |
| `max_amount_rial` | `BIGINT` | `NULLABLE` | Cap for percentage-based rewards |
| `daily_cap_rial` | `BIGINT` | `NULLABLE` | Maximum reward per user per day |
| `total_cap_rial` | `BIGINT` | `NULLABLE` | Maximum total reward per user |
| `min_trigger_amount_rial` | `BIGINT` | `NULLABLE` | Minimum charge/purchase to qualify |
| `valid_from` | `TIMESTAMP` | `NULLABLE` | Start date |
| `valid_until` | `TIMESTAMP` | `NULLABLE` | Expiry date |
| `is_active` | `BOOLEAN` | `NOT NULL DEFAULT FALSE` | |
| `refund_policy` | `VARCHAR(20)` | `NOT NULL DEFAULT 'no_clawback'` | `no_clawback`, `reverse_on_refund` |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `idx_gr_event_active (event_type, is_active, valid_from, valid_until)`

### 3.4 `broadcasts`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `admin_user_id` | `BIGINT UNSIGNED` | `NOT NULL FK → users.id` | Who initiated |
| `type` | `VARCHAR(20)` | `NOT NULL` | `text`, `forward` |
| `content_json` | `JSON` | `NOT NULL` | Message content or source message ID for forward |
| `target_filter` | `VARCHAR(30)` | `NOT NULL DEFAULT 'all'` | `all`, `active`, `non_blocked` |
| `total_recipients` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | Estimated at creation |
| `sent_count` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | Successfully delivered |
| `failed_count` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | Failed deliveries |
| `skipped_count` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | Skipped (blocked users) |
| `status` | `VARCHAR(20)` | `NOT NULL DEFAULT 'draft'` | `draft`, `confirmed`, `sending`, `paused`, `completed`, `cancelled` |
| `confirmed_at` | `TIMESTAMP` | `NULLABLE` | Two-step confirmation timestamp |
| `started_at` | `TIMESTAMP` | `NULLABLE` | |
| `completed_at` | `TIMESTAMP` | `NULLABLE` | |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `idx_bc_status (status, created_at)`, `idx_bc_admin (admin_user_id)`

### 3.5 `broadcast_deliveries`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `broadcast_id` | `BIGINT UNSIGNED` | `NOT NULL FK → broadcasts.id` | |
| `user_id` | `BIGINT UNSIGNED` | `NOT NULL FK → users.id` | |
| `status` | `VARCHAR(20)` | `NOT NULL DEFAULT 'queued'` | `queued`, `sent`, `failed`, `skipped` |
| `error_code` | `VARCHAR(50)` | `NULLABLE` | Telegram error code if failed |
| `telegram_message_id` | `BIGINT` | `NULLABLE` | Sent message ID |
| `sent_at` | `TIMESTAMP` | `NULLABLE` | |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `UNIQUE idx_bd_broadcast_user (broadcast_id, user_id)`, `idx_bd_status (broadcast_id, status)`

### 3.6 `daily_metrics`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `date` | `DATE` | `UNIQUE NOT NULL` | Metric date (Asia/Tehran) |
| `new_users` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | |
| `active_users` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | Users with activity on this date |
| `total_orders` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | |
| `completed_orders` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | |
| `failed_orders` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | |
| `gross_revenue_rial` | `BIGINT` | `NOT NULL DEFAULT 0` | Sum of paid invoices |
| `total_purchases_rial` | `BIGINT` | `NOT NULL DEFAULT 0` | Sum of order amounts |
| `total_refunds_rial` | `BIGINT` | `NOT NULL DEFAULT 0` | |
| `total_gifts_rial` | `BIGINT` | `NOT NULL DEFAULT 0` | |
| `total_referral_rewards_rial` | `BIGINT` | `NOT NULL DEFAULT 0` | |
| `new_referrals` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | |
| `blocked_users` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | Discovered blocked on this date |
| `snapshot_json` | `JSON` | `NULLABLE` | Additional metrics |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |

### 3.7 `users` Table Addition

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `is_blocked_bot` | `BOOLEAN` | `NOT NULL DEFAULT FALSE` | True if Telegram returns Forbidden |
| `referral_token` | `VARCHAR(20)` | `UNIQUE NULLABLE` | User's own referral token for sharing |

---

## 4. Actionable Tasks

### 4.1 Referral System

- [ ] Define `ReferralEvent` Enum: `signup`, `first_charge`, `charge`, `purchase`
- [ ] Define `RewardStatus` Enum: `pending`, `credited`, `rejected`
- [ ] Create migration: `referrals` (§3.1)
- [ ] Create migration: `referral_rewards` (§3.2)
- [ ] Create migration: add `is_blocked_bot`, `referral_token` to `users` (§3.7)
- [ ] Implement `GenerateReferralLinkAction`:
  - Generate short random token (8 chars, alphanumeric)
  - Store in `users.referral_token`
  - Return link: `https://t.me/{bot_username}?start=ref_{token}`
  - **Token is random — Telegram ID never exposed in link**
- [ ] Implement `AssignReferrerAction`:
  1. Parse `/start ref_{token}` payload
  2. Find referrer by `users.referral_token`
  3. Validate: not self-referral, referred user has no existing referrer, referrer is active
  4. Create `referrals` row
  5. Trigger `signup` gift rules if applicable
  6. **One-time only — immutable after assignment**
- [ ] Implement `ReferralValidationService`:
  - `canAssign(User $referred, User $referrer): bool` — checks all anti-fraud rules
  - No circular references (A referred B, B cannot refer A)
  - Referrer must have been active > configurable duration
  - Flag suspicious patterns: same IP range, burst signups
- [ ] Implement `ProcessReferralRewardAction`:
  1. Find applicable `gift_rules` for the event type
  2. Calculate reward amount (fixed or percent of trigger amount)
  3. Apply caps: max per reward, daily cap, total cap
  4. Check idempotency: `(referral_id, event, trigger_reference_type, trigger_reference_id)`
  5. Credit wallet via `CreditWalletAction` with reason "پاداش زیرمجموعه"
  6. Create `referral_rewards` row linked to `wallet_transactions`
- [ ] Update `StartHandler`: parse referral payload, call `AssignReferrerAction`
- [ ] Update `VerifyPaymentAction` (Phase 2): after successful payment, trigger referral charge reward
- [ ] Update `PlaceOrderAction` (Phase 1): after order placed, trigger referral purchase reward

### 4.2 Gift Rules Engine

- [ ] Define `GiftEventType` Enum: `signup`, `referrer_signup`, `referrer_charge`, `referrer_purchase`
- [ ] Create migration: `gift_rules` (§3.3)
- [ ] Create `GiftRuleSeeder`: default welcome gift (e.g., 5000 Rial), referrer signup bonus (e.g., 10000 Rial)
- [ ] Implement `GiftRuleEngine`:
  - `getApplicableRules(GiftEventType, ?int $triggerAmountRial): Collection`
  - Filter: `is_active`, within `valid_from`/`valid_until`, meets `min_trigger_amount`
  - Calculate: fixed amount or percentage with cap
  - Apply daily/total caps per user
- [ ] Implement `ApplyGiftAction`:
  1. Call `GiftRuleEngine` to get applicable rules
  2. For each rule: calculate amount, check caps
  3. Credit wallet with `reference_type = 'gift'`
  4. Record in `referral_rewards` or separate gift log
  5. Notify user: "🎁 هدیه {amount_toman} تومان به حساب شما اضافه شد"
- [ ] Implement admin gift rule management (filling Phase 4 stub):
  - `AdminGiftHandler`: list rules, toggle active
  - `CreateGiftRuleConversation`: event type, amount type/value, caps, validity
  - `EditGiftRuleConversation`: edit existing rule

### 4.3 Broadcast System

- [ ] Create migration: `broadcasts` (§3.4)
- [ ] Create migration: `broadcast_deliveries` (§3.5)
- [ ] Implement `CreateBroadcastAction`:
  1. Admin provides message text or forwards a message
  2. Select target filter: all / active / non-blocked
  3. Count estimated recipients
  4. Create `broadcasts` row with `status=draft`
- [ ] Implement `BroadcastConversation`:
  - Step 1: Choose type (new text or forward)
  - Step 2: Enter/forward message
  - Step 3: Select audience filter
  - Step 4: Preview message + show estimated recipients + show warning
  - Step 5: Two-step confirmation ("آیا مطمئن هستید؟ عملیات غیرقابل بازگشت است")
  - Step 6: Confirm → transition to `confirmed`, dispatch batch jobs
- [ ] Implement `SendBroadcastBatchJob`:
  1. Load batch of users (50 per job)
  2. For each user: send or forward message via `TelegramApiService`
  3. Handle responses:
     - Success: `BroadcastDelivery.status = 'sent'`
     - `Forbidden` / `bot blocked`: mark `sent` → `skipped`, set `users.is_blocked_bot = true` (do NOT delete user)
     - Rate limit (429): release job with delay
     - Other error: `failed` + log error code
  4. Update `broadcasts` counters: `sent_count`, `failed_count`, `skipped_count`
  5. If broadcast `paused` → stop processing, do not dispatch next batch
  6. If all deliveries processed → transition to `completed`
  7. **Rate limiting**: max 30 messages/second (Telegram limit)
- [ ] Implement `PauseBroadcastAction`: set status to `paused`, stop new batch dispatching
- [ ] Implement `ResumeBroadcastAction`: set status to `sending`, dispatch next batch
- [ ] Implement broadcast status display for admin:
  - Show: total, sent, failed, skipped, percentage complete
  - Show: last cron time, ETA
  - Buttons: pause/resume, cancel

### 4.4 Bot Statistics

- [ ] Create migration: `daily_metrics` (§3.6)
- [ ] Implement `DailyMetricsSnapshotJob`:
  - Run daily at 00:05 Asia/Tehran
  - Aggregate previous day's data:
    - `new_users`: users created yesterday
    - `active_users`: users with `last_active_at` yesterday
    - `total_orders`: orders created yesterday
    - `completed_orders`: orders completed yesterday
    - `gross_revenue_rial`: sum of `invoices.amount_rial` where `status=paid` yesterday
    - `total_purchases_rial`: sum of `orders.amount_rial` created yesterday
    - `total_refunds_rial`: sum of refund wallet_transactions yesterday
    - `total_gifts_rial`: sum of gift wallet_transactions yesterday
    - `total_referral_rewards_rial`: sum of referral rewards yesterday
    - `new_referrals`: referrals created yesterday
    - `blocked_users`: users newly marked `is_blocked_bot=true` yesterday
  - All amounts in **BIGINT Rial**

- [ ] Implement `AdminStatsHandler` (filling Phase 4 stub):
  ```
  📈 آمار ربات
  ├── 👥 کاربران: {total_active} فعال / {total} کل / {blocked} بلاک‌شده
  ├── 💰 موجودی کلی: {total_balance_toman} تومان
  ├── 📊 درآمد کل: {gross_revenue_toman} تومان
  ├── 📦 سفارش‌ها: {total} کل / {today} امروز / {processing} درحال / {completed} تکمیل / {failed} ناموفق
  ├── 🧾 فاکتورها: {total} کل / {paid} پرداخت / {failed} ناموفق / {manual_review} بررسی
  ├── 📅 ثبت‌نام: {today} امروز / {yesterday} دیروز
  ├── ⏰ Cron: آخرین اجرا {last_cron} / {failed_jobs} جاب ناموفق
  └── 💵 درآمد دوره‌ای: {today_toman} امروز / {week_toman} ۷ روز / {month_toman} ۳۰ روز
  ```
  - Use `daily_metrics` for historical data, live queries for today
  - All amounts displayed in Toman
  - Timezone: Asia/Tehran

### 4.5 User Flows

- [ ] Implement `ReferralHandler` ("زیرمجموعه‌گیری"):
  - Load/create referral token for user
  - Send banner image (from `file_id` cache or media asset)
  - Send referral link + stats:
    - "تعداد زیرمجموعه‌ها: {count}"
    - "مجموع پاداش: {total_reward_toman} تومان"
    - Explanation text from `message_templates`
- [ ] Implement `PriceListHandler` ("قیمت سرویس‌ها"):
  - Show active + visible categories
  - On category select: list services with `title`, `price_per_1000` (in Toman), `min/max`
  - Services without valid provider mapping not shown
  - No purchase action from price list (redirect to "خرید سرویس")

---

## 5. Jobs, Webhooks & Commands

### 5.1 Jobs

| Job | Queue | Trigger | Behavior |
|---|---|---|---|
| `SendBroadcastBatchJob` | `broadcast` | After broadcast confirmed | Send messages in batches of 50. Rate limit 30/sec. Handle blocked users. Update counters. Check for pause. |
| `DailyMetricsSnapshotJob` | `low` | Cron daily 00:05 Asia/Tehran | Aggregate yesterday's metrics into `daily_metrics` table. |
| `NotifyOpsChannelJob` | `notifications` | Business events | Extended: referral reward credited, gift applied, broadcast completed, suspicious referral pattern. |

### 5.2 Scheduler Additions

```php
Schedule::job(new DailyMetricsSnapshotJob)
    ->dailyAt('00:05')
    ->timezone('Asia/Tehran');
```

---

## 6. Testing Strategy

### 6.1 Unit Tests

| Test | What it verifies |
|---|---|
| `GiftRuleEngineTest` | Fixed amount calculation; percentage with cap; daily cap enforcement; total cap enforcement; validity period filter; inactive rules excluded |
| `ReferralValidationTest` | Self-referral rejected; already-referred user rejected; circular reference rejected; valid referral accepted |
| `ReferralTokenTest` | Token is 8 chars alphanumeric; unique over 1000 generations; Telegram ID not exposed |
| `MetricsCalculationTest` | Correct aggregation of orders, revenue, refunds per day; timezone boundary handling |
| `BroadcastBatchingTest` | 150 users → 3 batches of 50; batch respects rate limit |

### 6.2 Feature Tests

| Test | What it verifies |
|---|---|
| `ReferralSignupFlowTest` | User clicks `ref_{token}` link → registers → referrer assigned once → welcome gift credited |
| `ReferralDoubleAssignTest` | Same user clicks two different referral links → only first referrer assigned |
| `SelfReferralTest` | User's own referral link used by themselves → rejected |
| `ReferralChargeRewardTest` | Referred user charges wallet → referrer receives commission (integer math, BIGINT) |
| `GiftDailyCapTest` | After reaching daily cap → no more gifts for today → next day resets |
| `BroadcastFlowTest` | Admin creates → previews → confirms → messages sent via queue |
| `BroadcastPauseTest` | Admin pauses → no new batches dispatched → resume → continues |
| `BroadcastBlockedUserTest` | Telegram returns Forbidden → delivery marked skipped → `users.is_blocked_bot = true` → user NOT deleted |
| `PriceListTest` | Categories shown → services with price per 1000 (Toman) → inactive services hidden |
| `ReferralHandlerTest` | "زیرمجموعه‌گیری" shows link + correct count + total rewards |
| `StatsDisplayTest` | Admin stats show correct numbers matching DB aggregates |

### 6.3 Integration Tests

| Test | What it verifies |
|---|---|
| `SendBroadcastBatchJobTest` | Job sends messages to batch of users; blocked users detected; counters updated |
| `DailyMetricsSnapshotJobTest` | Job aggregates yesterday's data correctly into `daily_metrics` |
| `ReferralRewardIdempotencyTest` | Same charge event processed twice → only one reward credited |
| `GiftWithRefundPolicyTest` | Order refunded → gift with `reverse_on_refund` policy → gift reversed |

---

## 7. Acceptance Criteria

| # | Criterion | Verification Method |
|---|---|---|
| AC-1 | "زیرمجموعه‌گیری" shows unique referral link with format `t.me/{bot}?start=ref_{token}` — Telegram ID not in link | Manual + unit test |
| AC-2 | New user via referral link → referrer assigned once; re-clicking another referral link → no change | Feature test |
| AC-3 | Self-referral → rejected silently | Feature test |
| AC-4 | Welcome gift (if active): new user receives credit → `wallet_transactions` with `type=credit, reference_type=gift` | DB verify |
| AC-5 | Referrer charge commission: referred user charges 100,000 Toman → referrer receives configured percentage (BIGINT Rial, integer math) | Feature test + DB |
| AC-6 | Gift daily cap: after N rewards today → next reward returns 0 → next day resets | Feature test |
| AC-7 | Admin broadcast: preview → confirm → messages sent in batches → progress visible | Manual + integration test |
| AC-8 | Broadcast blocked user: Telegram `Forbidden` → delivery `skipped`, `users.is_blocked_bot=true`, user row still exists | Feature test |
| AC-9 | Admin can pause and resume broadcast mid-delivery | Feature test |
| AC-10 | "آمار ربات" displays correct: user count, balance total, revenue, orders by status, registrations, cron health | Manual verify against DB |
| AC-11 | All revenue/reward amounts in stats displayed in Toman; stored as BIGINT Rial | DB + display verify |
| AC-12 | `DailyMetricsSnapshotJob` runs at 00:05 Asia/Tehran and creates correct `daily_metrics` row | Integration test |
| AC-13 | "قیمت سرویس‌ها" shows active categories → services with per-1000 price in Toman; inactive/unmapped services hidden | Feature test |
| AC-14 | Referral reward idempotency: same trigger event processed twice → one reward | Integration test |
| AC-15 | Gift rule with `reverse_on_refund`: order refunded → gift amount reversed | Integration test |
