# Phase 2 — Wallet & Payment System: Online Gateways, Card-to-Card & OTP

---

## 1. Phase Goal

Deliver a **fully functional wallet top-up system** with multiple payment methods, server-side verification, and phone-based OTP authentication. By the end of this phase:

| Capability | Detail |
|---|---|
| Wallet top-up flow | User selects payment method → enters amount → pays → wallet credited |
| Online payment gateway | At least one Iranian gateway adapter (e.g., Zarinpal/Zibal sandbox) with `createPayment` + `verifyPayment` |
| Card-to-card manual flow | User sends receipt image/code → invoice goes to `manual_review` → operator approves/rejects |
| OTP verification | SMS-based phone verification via Melipayamak/Kavenegar with rate limiting |
| Invoice system | Full invoice lifecycle: `draft → awaiting_payment → callback_received → verifying → paid` |
| Payment callback webhook | Secure endpoint receiving gateway callbacks with idempotent processing |
| Expire stale invoices | Cron job to expire unpaid invoices past `expires_at` |
| Account info display | "اطلاعات حساب" shows balance, total payments, total purchases, orders count |

> **Architectural Constraints enforced in this phase:**
> - All amounts stored as `BIGINT` (Rial). Toman in UI only.
> - Wallet ledger is **append-only**. No edits, no soft-deletes on financial records.
> - Payment verify MUST happen **server-side**. Browser/callback is just a signal.
> - Invoice + wallet credit is **idempotent**: duplicate callbacks produce one credit.
> - Gateway calls dispatched via **Queue Job**, not inside webhook/callback response.

---

## 2. Modules & Architecture

### 2.1 Directory Structure Affected

```
app/Modules/
├── Payments/
│   ├── Contracts/
│   │   └── PaymentGatewayContract.php
│   ├── Adapters/
│   │   ├── IranianGatewayAdapter.php        # e.g. Zarinpal
│   │   ├── ManualCardToCardAdapter.php
│   │   └── MockGatewayAdapter.php            # For testing
│   ├── Models/
│   │   ├── PaymentMethod.php
│   │   ├── Invoice.php
│   │   ├── Payment.php
│   │   └── PaymentCallback.php
│   ├── Enums/
│   │   ├── InvoiceStatus.php
│   │   └── PaymentMethodType.php
│   ├── Actions/
│   │   ├── CreateInvoiceAction.php
│   │   ├── ProcessPaymentCallbackAction.php
│   │   ├── VerifyPaymentAction.php
│   │   ├── ApproveManualPaymentAction.php
│   │   └── RejectManualPaymentAction.php
│   ├── Services/
│   │   └── PaymentMethodQueryService.php
│   ├── Factory/
│   │   └── PaymentGatewayFactory.php
│   └── Providers/
│       └── PaymentServiceProvider.php
│
├── Telegram/
│   ├── Conversations/
│   │   ├── TopUpConversation.php              # NEW
│   │   └── OtpVerificationConversation.php    # NEW
│   ├── Handlers/
│   │   ├── AccountInfoHandler.php             # NEW
│   │   ├── CardToCardReceiptHandler.php       # NEW
│   │   └── AdminManualPaymentHandler.php      # NEW (stub for Phase 4)
│   └── Keyboards/
│       ├── PaymentMethodKeyboard.php          # NEW
│       └── ManualPaymentReviewKeyboard.php    # NEW
│
├── Wallet/   # Extended from Phase 1
│   └── Actions/
│       └── CreditWalletAction.php             # Already exists; verified for payment use
│
└── Shared/
    └── Services/
        └── OtpService.php                     # NEW

app/Http/Controllers/Webhooks/
└── PaymentCallbackController.php              # NEW

app/Jobs/
├── VerifyPaymentJob.php                       # NEW
├── ExpireInvoicesJob.php                      # NEW
└── NotifyOpsChannelJob.php                    # NEW (payment events)

database/migrations/
├── 0011_create_payment_methods_table.php
├── 0012_create_invoices_table.php
├── 0013_create_payments_table.php
├── 0014_create_payment_callbacks_table.php
├── 0015_create_otp_verifications_table.php
└── 0016_add_card_number_to_users_table.php

config/
├── payments.php                               # NEW
└── otp.php                                    # NEW
```

### 2.2 Module Dependency Graph (Phase 2 additions)

```
Telegram → Payments (create invoice, initiate flow)
         → Shared/OtpService (verify phone)
Payments → Wallet (credit on successful payment)
         → Shared (idempotency, audit, Money VO)
PaymentCallbackController → Payments (process callback)
VerifyPaymentJob → Payments (server-side verify) → Wallet (credit)
```

---

## 3. Database Schema

### 3.1 `payment_methods`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `type` | `VARCHAR(30)` | `NOT NULL` | `online_gateway`, `card_to_card`, `crypto`, `telegram_stars` |
| `driver` | `VARCHAR(50)` | `NOT NULL` | `zarinpal`, `zibal`, `manual_card`, `mock` — from allowlist |
| `name` | `VARCHAR(255)` | `NOT NULL` | Display name in Farsi |
| `config_encrypted_json` | `TEXT` | `NOT NULL` | Laravel encrypted: merchant ID, API keys, card number for C2C |
| `min_amount_rial` | `BIGINT` | `NOT NULL DEFAULT 10000` | Minimum top-up in Rial |
| `max_amount_rial` | `BIGINT` | `NOT NULL DEFAULT 500000000` | Maximum top-up in Rial |
| `requires_otp` | `BOOLEAN` | `NOT NULL DEFAULT FALSE` | Whether OTP is required for this method |
| `is_active` | `BOOLEAN` | `NOT NULL DEFAULT FALSE` | |
| `sort_order` | `INT` | `NOT NULL DEFAULT 0` | Display order |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `idx_pm_active_sort (is_active, sort_order)`

### 3.2 `invoices`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `user_id` | `BIGINT UNSIGNED` | `NOT NULL FK → users.id` | **Immutable after creation** |
| `payment_method_id` | `BIGINT UNSIGNED` | `NOT NULL FK → payment_methods.id` | |
| `invoice_code` | `VARCHAR(30)` | `UNIQUE NOT NULL` | `INV-YYYYMMDD-XXXXXX` |
| `amount_rial` | `BIGINT` | `NOT NULL` | **BIGINT — Immutable after creation** |
| `status` | `VARCHAR(30)` | `NOT NULL DEFAULT 'draft'` | `InvoiceStatus` enum |
| `idempotency_key` | `VARCHAR(100)` | `UNIQUE NOT NULL` | Prevents duplicate invoices |
| `gateway_authority` | `VARCHAR(255)` | `NULLABLE` | Authority/token from gateway |
| `gateway_payment_url` | `VARCHAR(500)` | `NULLABLE` | Redirect URL for user |
| `expires_at` | `TIMESTAMP` | `NOT NULL` | Auto-expire if not paid |
| `paid_at` | `TIMESTAMP` | `NULLABLE` | |
| `verified_at` | `TIMESTAMP` | `NULLABLE` | Server-side verification timestamp |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `UNIQUE idx_inv_code (invoice_code)`, `UNIQUE idx_inv_idempotency (idempotency_key)`, `idx_inv_user_status (user_id, status, created_at)`, `idx_inv_expires (status, expires_at)`

> ⚠️ `amount_rial` and `user_id` columns are **never updated** after row insertion. Only `status`, timestamps, and gateway fields may be updated.

### 3.3 `payments`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `invoice_id` | `BIGINT UNSIGNED` | `NOT NULL FK → invoices.id` | |
| `gateway_ref_id` | `VARCHAR(255)` | `NULLABLE` | Gateway reference/transaction ID |
| `gateway_authority` | `VARCHAR(255)` | `NULLABLE` | Authority used for verification |
| `amount_rial` | `BIGINT` | `NOT NULL` | Amount confirmed by gateway |
| `card_pan_masked` | `VARCHAR(20)` | `NULLABLE` | e.g. `6037-99**-****-1234` |
| `verified_at` | `TIMESTAMP` | `NULLABLE` | |
| `raw_payload_redacted_json` | `JSON` | `NULLABLE` | Gateway response with secrets removed |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `UNIQUE idx_pay_gateway_ref (gateway_ref_id)` (when not null), `idx_pay_invoice (invoice_id)`

### 3.4 `payment_callbacks`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `invoice_id` | `BIGINT UNSIGNED` | `NULLABLE FK → invoices.id` | Resolved from callback data |
| `source` | `VARCHAR(50)` | `NOT NULL` | Gateway driver name |
| `raw_payload_redacted_json` | `JSON` | `NOT NULL` | Full callback payload, secrets redacted |
| `ip_address` | `VARCHAR(45)` | `NULLABLE` | Sender IP |
| `processing_result` | `VARCHAR(30)` | `NULLABLE` | `accepted`, `duplicate`, `rejected`, `error` |
| `idempotency_hash` | `VARCHAR(64)` | `UNIQUE NOT NULL` | SHA-256 of critical callback fields |
| `created_at` | `TIMESTAMP` | `NOT NULL` | **Append-only** |

**Indexes:** `UNIQUE idx_pcb_idempotency (idempotency_hash)`, `idx_pcb_invoice (invoice_id, created_at)`

### 3.5 `otp_verifications`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `user_id` | `BIGINT UNSIGNED` | `NOT NULL FK → users.id` | |
| `phone_e164` | `VARCHAR(20)` | `NOT NULL` | Target phone number |
| `code_hash` | `VARCHAR(255)` | `NOT NULL` | Hashed OTP code — **never store plaintext** |
| `purpose` | `VARCHAR(30)` | `NOT NULL` | `payment`, `identity` |
| `attempts` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | Failed attempt counter |
| `max_attempts` | `INT UNSIGNED` | `NOT NULL DEFAULT 3` | |
| `verified_at` | `TIMESTAMP` | `NULLABLE` | |
| `expires_at` | `TIMESTAMP` | `NOT NULL` | Short TTL: 2-5 minutes |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `idx_otp_user_purpose (user_id, purpose, created_at)`, `idx_otp_phone_created (phone_e164, created_at)`

### 3.6 `users` Table Addition

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `card_number_encrypted` | `TEXT` | `NULLABLE` | Encrypted; shown masked in UI |

> Added via `0016_add_card_number_to_users_table.php` migration.

---

## 4. Actionable Tasks

### 4.1 Payment Module Foundation

- [ ] Create `InvoiceStatus` Enum: `draft`, `awaiting_payment`, `callback_received`, `verifying`, `paid`, `failed`, `expired`, `manual_review`, `cancelled`
- [ ] Create `PaymentMethodType` Enum: `online_gateway`, `card_to_card`, `crypto`, `telegram_stars`
- [ ] Create migration: `payment_methods` (§3.1)
- [ ] Create migration: `invoices` (§3.2) — ensure `amount_rial` + `user_id` are not in `$fillable` (set only on creation)
- [ ] Create migration: `payments` (§3.3)
- [ ] Create migration: `payment_callbacks` (§3.4) — append-only
- [ ] Create migration: `otp_verifications` (§3.5)
- [ ] Create migration: add `card_number_encrypted` to `users` (§3.6)
- [ ] Create `PaymentMethod` model with encrypted cast for `config_encrypted_json`
- [ ] Create `Invoice` model: protect `amount_rial`/`user_id` from update; status transitions validated
- [ ] Create `Payment` model
- [ ] Create `PaymentCallback` model — append-only, no `update()`/`delete()`
- [ ] Create `OtpVerification` model
- [ ] Create seeder: one `payment_methods` row for mock gateway + one for card-to-card

### 4.2 Payment Gateway Contract & Adapters

- [ ] Define `PaymentGatewayContract` interface:
  ```php
  interface PaymentGatewayContract {
      public function createPayment(Invoice $invoice): PaymentInitiationResult;
      public function verifyPayment(Invoice $invoice, array $callbackPayload): VerificationResult;
      public function supportsRefund(): bool;
      public function refund(Payment $payment): RefundResult;
  }
  ```
- [ ] Create DTOs: `PaymentInitiationResult` (success, authority, paymentUrl), `VerificationResult` (verified, refId, amount, cardPan), `RefundResult`
- [ ] Implement `MockGatewayAdapter` for testing (returns predictable authority + verification)
- [ ] Implement `IranianGatewayAdapter` for Zarinpal (sandbox mode):
  - `createPayment()`: POST to `https://sandbox.zarinpal.com/pg/v4/payment/request.json`
  - `verifyPayment()`: POST to `https://sandbox.zarinpal.com/pg/v4/payment/verify.json`
  - HTTPS only, timeout 15s, redact merchant_id in logs
- [ ] Implement `ManualCardToCardAdapter`:
  - `createPayment()`: returns card number (from config, masked for user) + instructions
  - `verifyPayment()`: always returns `pending_manual_review` — requires operator action
- [ ] Implement `PaymentGatewayFactory`: resolves adapter from `payment_methods.driver`
- [ ] Register adapters in `PaymentServiceProvider` with DI

### 4.3 Payment Actions & Flow

- [ ] Implement `CreateInvoiceAction`:
  1. Validate amount: min/max boundaries, integer Rial, no float
  2. Generate `invoice_code` and `idempotency_key`
  3. Create `Invoice` with `status=draft`
  4. Resolve `PaymentGatewayContract` via factory
  5. For online: call `createPayment()` → store `gateway_authority` + `gateway_payment_url` → set `status=awaiting_payment`
  6. For card-to-card: return card info + instructions → set `status=awaiting_payment`
  7. Set `expires_at` (online: 30 min, card-to-card: 60 min)
  8. Return payment link/instructions to user

- [ ] Implement `ProcessPaymentCallbackAction`:
  1. Log raw callback in `payment_callbacks` (with redaction)
  2. Compute `idempotency_hash` from critical fields
  3. If hash exists → return `duplicate`, do nothing
  4. Resolve `Invoice` from callback data (authority/invoice_code)
  5. If invoice not found or not `awaiting_payment` → reject
  6. Transition to `callback_received`
  7. Dispatch `VerifyPaymentJob`

- [ ] Implement `VerifyPaymentAction` (called by job):
  1. Transition invoice to `verifying`
  2. Call `gateway.verifyPayment()` — **server-side only**
  3. Validate: `verifiedAmount === invoice.amount_rial`
  4. If mismatch → `failed` + log discrepancy + notify ops
  5. If verified:
     - Begin DB transaction
     - Check idempotency (invoice not already `paid`)
     - Create `Payment` record
     - Call `CreditWalletAction` with `idempotency_key = invoice:{invoice_id}`
     - Transition invoice to `paid`, set `paid_at` + `verified_at`
     - Commit
     - Notify user via Telegram
     - Notify ops channel

- [ ] Implement `ApproveManualPaymentAction`:
  1. Operator provides `invoice_id`, `ref_code`, `reason`
  2. Validate invoice is `manual_review`
  3. Begin DB transaction
  4. Create `Payment` record with operator-provided ref
  5. Call `CreditWalletAction`
  6. Transition to `paid`
  7. Log in `audit_logs` with operator as actor
  8. Commit
  9. Notify user

- [ ] Implement `RejectManualPaymentAction`:
  1. Transition to `failed` with reason
  2. Log audit
  3. Notify user with rejection reason

### 4.4 OTP Service

- [ ] Create `config/otp.php`: provider (melipayamak), API key (from .env), TTL (120s), max attempts (3), cooldown between sends (60s), max sends per phone per day (5)
- [ ] Implement `OtpService`:
  - `send(User $user, string $phone, string $purpose): OtpSendResult`
    1. Validate phone format (Iranian mobile: `09XXXXXXXXX` → `+989XXXXXXXXX`)
    2. Check cooldown: last OTP for this user/phone must be > 60s ago
    3. Check daily limit: max 5 OTPs per phone per day
    4. Generate 6-digit code
    5. Hash code with `Hash::make()`
    6. Store in `otp_verifications`
    7. Dispatch SMS via provider (async, queue job)
    8. Return success/cooldown/limit_reached
  - `verify(User $user, string $code, string $purpose): OtpVerifyResult`
    1. Find latest non-expired, non-verified OTP for user+purpose
    2. Check attempts < max_attempts
    3. Increment attempts
    4. `Hash::check(code, code_hash)`
    5. If match: set `verified_at`, update `users.phone_e164` + `phone_verified_at`
    6. If no match: return failure with remaining attempts
- [ ] Implement SMS sending (abstracted behind interface for future providers)
- [ ] **Never log OTP code in plaintext** — only hash stored

### 4.5 Telegram Conversations

- [ ] Implement `TopUpConversation`:
  - Step 1: Show active payment methods → `PaymentMethodKeyboard`
  - Step 2: If method requires OTP and user not verified → redirect to `OtpVerificationConversation`
  - Step 3: Ask for amount in Toman → validate min/max/integer
  - Step 4: Convert Toman → Rial (`amount * 10`), display confirmation
  - Step 5: Call `CreateInvoiceAction`
  - Step 6a (Online): Send payment link as inline URL button
  - Step 6b (Card-to-card): Show card number + instructions + ask for receipt image/code
- [ ] Implement `OtpVerificationConversation`:
  - Step 1: Ask for mobile number (Iranian format)
  - Step 2: Call `OtpService::send()` → show "کد ارسال شد"
  - Step 3: Ask for 6-digit code
  - Step 4: Call `OtpService::verify()` → success/retry/fail
  - On success: return to calling conversation
- [ ] Implement `AccountInfoHandler`:
  - Display: name, Telegram ID, wallet balance (Toman), total paid invoices (Toman), total purchases (Toman), order count, referral count, join date
- [ ] Implement `CardToCardReceiptHandler`:
  - User sends photo or tracking code after card-to-card
  - Save file/text reference to invoice
  - Transition invoice to `manual_review`
  - Notify ops channel with `NotifyOpsChannelJob`

### 4.6 Payment Callback Controller

- [ ] Create `PaymentCallbackController`:
  - Route: `GET|POST /payments/{gateway}/callback`
  - Exempt from CSRF (added to `$except` in middleware)
  - Validate gateway name against driver allowlist
  - Call `ProcessPaymentCallbackAction`
  - For GET (browser redirect): show "در حال بررسی..." page, user returns to bot
  - For POST (server callback): return 200 OK

### 4.7 Scheduled Jobs

- [ ] Implement `ExpireInvoicesJob`:
  - Find invoices where `status IN ('awaiting_payment', 'callback_received') AND expires_at < NOW()`
  - Transition each to `expired`
  - Clean related `conversation_states` if any
  - Log count of expired invoices

---

## 5. Jobs, Webhooks & Commands

### 5.1 Jobs

| Job | Queue | Trigger | Behavior |
|---|---|---|---|
| `VerifyPaymentJob` | `payments` | After callback received | Server-side verify with gateway → credit wallet if valid → notify user + ops |
| `ExpireInvoicesJob` | `low` | Cron (every 5 min) | Expire unpaid invoices past `expires_at` |
| `SendOtpSmsJob` | `default` | OTP send request | Send SMS via provider API; log result without exposing code |
| `NotifyOpsChannelJob` | `notifications` | Payment events | Forward payment/review notifications to ops Telegram channel |

### 5.2 Webhooks

| Endpoint | Method | Security | Responsibility |
|---|---|---|---|
| `GET\|POST /payments/{gateway}/callback` | GET/POST | Driver allowlist + signature/authority validation + idempotency hash | Receive payment gateway callbacks. Log → deduplicate → dispatch `VerifyPaymentJob`. Never trust callback alone — always verify server-side. |

### 5.3 Scheduler Additions

```php
Schedule::job(new ExpireInvoicesJob)->everyFiveMinutes();
```

---

## 6. Testing Strategy

### 6.1 Unit Tests

| Test | What it verifies |
|---|---|
| `InvoiceStatusEnumTest` | Valid transitions: `draft → awaiting_payment → callback_received → verifying → paid`; invalid: `paid → draft` |
| `MoneyConversionTest` | `tomanToRial(100) === 1000`; `rialToToman(1000) === 100`; `rialToToman(999) === 99` (floor) |
| `OtpServiceTest` | Code generation is 6 digits; hash verification works; expired codes rejected; max attempts enforced |
| `PhoneValidationTest` | `09123456789 → +989123456789`; invalid formats rejected; international numbers rejected |

### 6.2 Feature Tests

| Test | What it verifies |
|---|---|
| `TopUpConversationTest` | Full flow: select method → enter amount → receive payment link/card info |
| `CreateInvoiceTest` | Invoice created with correct Rial amount; min/max validated; idempotency key set |
| `PaymentCallbackTest` | Valid callback → `callback_received`; duplicate callback → ignored; wrong authority → rejected |
| `VerifyPaymentTest` | After verification: invoice `paid`, wallet credited once, user notified |
| `IdempotentPaymentTest` | Same callback processed twice → only one `wallet_transaction` created |
| `ManualPaymentFlowTest` | Card-to-card: invoice → `manual_review` → operator approve → wallet credited |
| `ManualPaymentRejectTest` | Operator reject → invoice `failed`, no credit, user notified |
| `OtpVerificationTest` | Send OTP → verify correct code → phone saved; wrong code → attempts incremented; expired → rejected |
| `OtpRateLimitTest` | Sending OTP within cooldown → rejected; exceeding daily limit → rejected |
| `AccountInfoTest` | Displays correct balance, payment totals, order count |

### 6.3 Integration Tests

| Test | What it verifies |
|---|---|
| `VerifyPaymentJobTest` | Full job execution with mock gateway: verify → credit → notify |
| `AmountMismatchTest` | Gateway reports different amount → invoice `failed`, no credit, ops notified |
| `ExpireInvoicesJobTest` | Stale invoices transitioned to `expired`; already-paid invoices untouched |
| `ConcurrentPaymentTest` | Two callbacks for same invoice → only one processes, second is `duplicate` |

### 6.4 Contract Tests

| Test | What it verifies |
|---|---|
| `ZarinpalAdapterContractTest` | Fixture request/response for `createPayment` and `verifyPayment` against sandbox |
| `ManualCardAdapterContractTest` | `createPayment` returns card info; `verifyPayment` returns `pending_manual_review` |

---

## 7. Acceptance Criteria

| # | Criterion | Verification Method |
|---|---|---|
| AC-1 | "شارژ حساب" shows only active payment methods in correct sort order | Manual: tap menu, verify against DB |
| AC-2 | Entering amount below minimum → Farsi error with correct minimum in Toman | Manual + feature test |
| AC-3 | Entering amount above maximum → Farsi error with correct maximum in Toman | Manual + feature test |
| AC-4 | Amount entered in Toman; stored in DB as Rial (`amount * 10`); no float anywhere | DB check: `invoices.amount_rial` |
| AC-5 | Online payment: clicking link opens gateway; after mock payment, wallet credited with exact amount | E2E with sandbox gateway |
| AC-6 | Duplicate callback for same invoice → only one `wallet_transactions` credit row exists | Feature test + DB query |
| AC-7 | Card-to-card: user receives card number (masked), sends receipt, invoice → `manual_review` | Manual flow |
| AC-8 | Operator approves card-to-card → wallet credited, `audit_logs` records operator as actor | DB check |
| AC-9 | Operator rejects card-to-card → invoice `failed`, no credit, user receives rejection message | Manual flow |
| AC-10 | OTP: code sent via SMS, 6 digits, expires in 2 min, max 3 attempts | Feature test |
| AC-11 | OTP: wrong code 3 times → locked, must request new code | Feature test |
| AC-12 | OTP: sending within 60s cooldown → rate limit error | Feature test |
| AC-13 | Invoice not paid within `expires_at` → `ExpireInvoicesJob` transitions to `expired` | Integration test |
| AC-14 | "اطلاعات حساب" correctly shows: balance, total payments, total purchases, order count, join date | Manual verification |
| AC-15 | No payment tokens, merchant IDs, or full card numbers appear in any log file or Telegram message | Security test: grep logs |
| AC-16 | `invoices.amount_rial` and `invoices.user_id` cannot be changed after creation | Unit test: attempt update throws |
| AC-17 | `payment_callbacks` table is append-only — no update/delete | Unit test |
| AC-18 | All financial columns use `BIGINT` — verify with `SHOW COLUMNS` | Migration review |
| AC-19 | Payment gateway call happens in `VerifyPaymentJob`, not in callback controller | Code review + test timing |
