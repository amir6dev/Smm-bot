# Phase 3 — SMM Integrations: Real Adapters, Status Sync & Error Handling

---

## 1. Phase Goal

Replace the mock SMM adapter from Phase 1 with **production-ready V1 and V2 API adapters**, implement automated order status synchronization, and build robust error handling with controlled retry and refund logic. By the end of this phase:

| Capability | Detail |
|---|---|
| V1ProviderAdapter | Full adapter for SMM panels following API V1 conventions |
| V2ProviderAdapter | Full adapter for SMM panels following API V2 conventions |
| Provider Profile management | `smm_providers` fully configured with encrypted tokens, capabilities, status mapping |
| Connection testing | `testConnection()` validates DNS, HTTPS, auth, and response parsing |
| Automated status sync | `SyncOrderStatusJob` polls provider for active orders on a cron schedule |
| Provider health checks | `ProviderHealthCheckJob` periodically tests active providers |
| Connection logging | All provider interactions logged in `smm_connection_logs` |
| Error classification | Retriable vs. terminal errors with exponential backoff |
| Automatic refund | Terminal submission failures trigger wallet refund |
| Cancel/Refill support | Optional `cancelOrder()` and `refillOrder()` when provider supports them |
| Contract tests | Fixture-based tests for V1 and V2 response parsing |

> **Architectural Constraints enforced in this phase:**
> - SMM API calls are **always dispatched via Queue Jobs** — never in webhook response.
> - Provider tokens are **encrypted at rest**; decrypted only at call time; **never logged**.
> - `base_url` validated against HTTPS-only allowlist; SSRF protection enforced.
> - Order status transitions follow the canonical `OrderStatus` enum — no raw strings.

---

## 2. Modules & Architecture

### 2.1 Directory Structure Affected

```
app/Modules/
├── Smm/
│   ├── Contracts/
│   │   └── SmmProviderContract.php            # Exists from Phase 1
│   ├── Adapters/
│   │   ├── MockProviderAdapter.php            # Phase 1 (kept for testing)
│   │   ├── V1ProviderAdapter.php              # NEW
│   │   ├── V2ProviderAdapter.php              # NEW
│   │   └── Concerns/
│   │       ├── HandlesHttpRequests.php         # NEW: shared HTTP logic
│   │       ├── MapsProviderStatus.php          # NEW: status normalization
│   │       └── RedactsSecrets.php              # NEW: log redaction
│   ├── DTOs/
│   │   ├── NormalizedOrderPayload.php         # Phase 1
│   │   ├── ProviderOrderResult.php            # Phase 1
│   │   ├── ProviderOrderStatusResult.php      # Phase 1
│   │   ├── ProviderBalanceResult.php          # Phase 1
│   │   ├── ProviderServiceCollection.php      # Phase 1
│   │   ├── ConnectionResult.php               # Phase 1
│   │   ├── ProviderActionResult.php           # Phase 1
│   │   └── ProviderService.php                # NEW: individual service DTO
│   ├── Models/
│   │   └── SmmConnectionLog.php               # NEW
│   ├── Services/
│   │   ├── ProviderStatusMapper.php           # NEW
│   │   └── ProviderHealthService.php          # NEW
│   ├── Exceptions/
│   │   ├── ProviderAuthenticationException.php    # NEW
│   │   ├── ProviderTimeoutException.php           # NEW
│   │   ├── ProviderApiException.php               # NEW
│   │   ├── ProviderRateLimitException.php         # NEW
│   │   └── ProviderUnavailableException.php       # NEW
│   ├── Factory/
│   │   └── SmmProviderFactory.php             # Phase 1 (extended)
│   └── Providers/
│       └── SmmServiceProvider.php             # Phase 1 (extended)
│
├── Orders/
│   ├── Actions/
│   │   ├── PlaceOrderAction.php               # Phase 1
│   │   ├── RefundOrderAction.php              # Phase 1 (enhanced)
│   │   ├── SyncOrderStatusAction.php          # NEW
│   │   └── ProcessPartialOrderAction.php      # NEW
│   └── Services/
│       └── OrderStatusSyncService.php         # NEW

app/Jobs/
├── SubmitSmmOrderJob.php                      # Phase 1 (enhanced with real adapters)
├── SyncOrderStatusJob.php                     # NEW
├── ProviderHealthCheckJob.php                 # NEW
└── NotifyOpsChannelJob.php                    # Phase 2 (extended for SMM events)

database/migrations/
└── 0017_create_smm_connection_logs_table.php  # NEW

tests/
├── Contract/
│   ├── V1ProviderAdapterTest.php              # NEW
│   └── V2ProviderAdapterTest.php              # NEW
├── Unit/
│   ├── ProviderStatusMapperTest.php           # NEW
│   └── V1ResponseParserTest.php               # NEW
├── Feature/
│   ├── SubmitRealOrderTest.php                # NEW
│   └── SyncOrderStatusTest.php                # NEW
└── Integration/
    ├── SubmitSmmOrderJobRealTest.php           # NEW
    └── ProviderHealthCheckJobTest.php          # NEW
```

### 2.2 Module Dependency Graph (Phase 3 additions)

```
Orders → Smm (submit order, sync status)
Smm → Shared (Money VO, audit, redaction)
SubmitSmmOrderJob → Smm (adapter) → Orders (update status)
SyncOrderStatusJob → Smm (adapter) → Orders (update status) → Wallet (refund if needed)
ProviderHealthCheckJob → Smm (test connection)
```

---

## 3. Database Schema

### 3.1 `smm_connection_logs`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `provider_id` | `BIGINT UNSIGNED` | `NOT NULL FK → smm_providers.id` | |
| `action` | `VARCHAR(50)` | `NOT NULL` | `test_connection`, `get_balance`, `get_services`, `create_order`, `get_status`, `cancel_order`, `refill_order` |
| `request_method` | `VARCHAR(10)` | `NOT NULL` | `POST`, `GET` |
| `request_url_redacted` | `VARCHAR(500)` | `NOT NULL` | URL with token param removed |
| `http_status` | `INT` | `NULLABLE` | Response HTTP status code |
| `latency_ms` | `INT UNSIGNED` | `NULLABLE` | Request duration |
| `result` | `VARCHAR(30)` | `NOT NULL` | `success`, `auth_error`, `timeout`, `parse_error`, `rate_limited`, `server_error`, `network_error` |
| `error_code` | `VARCHAR(100)` | `NULLABLE` | Provider error code if available |
| `error_message_redacted` | `VARCHAR(500)` | `NULLABLE` | Error message with secrets removed |
| `redacted_response_json` | `JSON` | `NULLABLE` | Response body with tokens/keys removed |
| `order_id` | `BIGINT UNSIGNED` | `NULLABLE FK → orders.id` | If related to an order operation |
| `created_at` | `TIMESTAMP` | `NOT NULL` | **Append-only** |

**Indexes:** `idx_scl_provider_created (provider_id, created_at)`, `idx_scl_action_result (action, result, created_at)`, `idx_scl_order (order_id)`

### 3.2 `smm_providers` — Additional Columns (Migration Update)

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `priority` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | For future multi-provider routing |
| `last_error_at` | `TIMESTAMP` | `NULLABLE` | |
| `last_error_message` | `VARCHAR(500)` | `NULLABLE` | Redacted |
| `consecutive_failures` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | Auto-disable threshold |

> Added via `0018_add_health_columns_to_smm_providers.php`

---

## 4. Actionable Tasks

### 4.1 SMM Provider Contract Refinement

- [ ] Review and finalize `SmmProviderContract` interface methods and return types
- [ ] Create `ProviderService` DTO for individual service data from provider
- [ ] Add `capabilities()` method to contract returning supported actions list
- [ ] Add `mapStatus(string $externalStatus): OrderStatus` to contract

### 4.2 V1 Provider Adapter

- [ ] Implement `V1ProviderAdapter` with the following API convention:
  ```
  POST {base_url}
  Content-Type: application/x-www-form-urlencoded

  # Actions via `action` parameter:
  action=services&key={api_key}
  action=add&key={api_key}&service={id}&link={link}&quantity={qty}
  action=status&key={api_key}&order={order_id}
  action=balance&key={api_key}
  action=cancel&key={api_key}&order={order_id}       # optional
  action=refill&key={api_key}&order={order_id}       # optional
  ```
- [ ] Implement request building: form-encoded body, single endpoint URL
- [ ] Implement response parsing for V1 format:
  - `add` response: `{"order": 12345}` or `{"error": "message"}`
  - `status` response: `{"charge": 100, "start_count": 900, "status": "In progress", "remains": 50}`
  - `balance` response: `{"balance": "100.5", "currency": "USD"}`
  - `services` response: array of `{"service": 1, "name": "...", "rate": "0.5", "min": 100, "max": 10000, ...}`
- [ ] Map V1 status strings to canonical `OrderStatus`: `Pending → queued`, `In progress → processing`, `Completed → completed`, `Partial → partial`, `Canceled → cancelled`, `Refunded → refunded`
- [ ] Handle V1 error responses: detect `error` key, classify error type
- [ ] Token is sent as `key` parameter — **never logged**

### 4.3 V2 Provider Adapter

- [ ] Implement `V2ProviderAdapter` with the following API convention:
  ```
  POST {base_url}
  Content-Type: application/json
  Authorization: Bearer {api_key}

  # Separate endpoints or action-based:
  {"action": "services"}
  {"action": "add", "service": 1, "link": "...", "quantity": 100}
  {"action": "status", "order": 12345}
  {"action": "balance"}
  ```
- [ ] Implement JSON body building + Bearer auth header
- [ ] Implement response parsing for V2 format (JSON response with status codes)
- [ ] Map V2 status values to canonical `OrderStatus` (may differ from V1)
- [ ] Handle V2 error format: HTTP status codes + JSON error body
- [ ] Token is sent as Bearer header — **never logged**

### 4.4 Shared HTTP Concerns

- [ ] Implement `HandlesHttpRequests` trait:
  - Configurable `connect_timeout` and `total_timeout` from provider settings
  - Retry logic with exponential backoff (configurable `max_retries`)
  - HTTPS-only enforcement: reject `http://` URLs
  - SSRF protection: validate host against denylist (private IPs, localhost)
  - No redirect following for POST requests
  - Log every request/response to `smm_connection_logs` with redaction
- [ ] Implement `RedactsSecrets` trait:
  - Remove `key`, `api_key`, `token`, `Authorization` from logged payloads
  - Mask URL query parameters containing tokens
  - Remove sensitive fields from response JSON before storage
- [ ] Implement `MapsProviderStatus` trait:
  - Load `status_mapping_json` from `smm_providers` table
  - If custom mapping exists, use it; else use adapter defaults
  - Log unmapped status values as warnings

### 4.5 Connection Testing

- [ ] Implement `testConnection()` in each adapter:
  1. DNS resolution check (host resolves)
  2. HTTPS certificate validation
  3. Send `balance` or `services` action (least destructive)
  4. Validate HTTP response code (2xx)
  5. Parse response body (valid JSON/expected format)
  6. Verify authentication (no auth error in response)
  7. Return `ConnectionResult` with latency, status, parsed data
- [ ] Log result in `smm_connection_logs` with `action=test_connection`
- [ ] Update `smm_providers`: `last_health_check_at`, `last_health_status`, `last_balance`

### 4.6 Enhanced SubmitSmmOrderJob

- [ ] Update `SubmitSmmOrderJob` for real adapters:
  1. Load order (assert status `queued`)
  2. Transition to `submitting` + record `OrderEvent`
  3. Resolve provider adapter via `SmmProviderFactory`
  4. Build `NormalizedOrderPayload` from order data
  5. Call `adapter->createOrder(payload)`
  6. On success:
     - Save `provider_order_id` + `provider_status`
     - Transition to `submitted`
     - Record `OrderEvent` with redacted provider response
     - Notify user: "سفارش شما با کد {order_code} ثبت شد"
  7. On retriable error (timeout, rate limit, 5xx):
     - Log in `smm_connection_logs`
     - Leave status as `queued` (or keep `submitting`)
     - Release job with exponential backoff: `$this->release(2 ** $this->attempts() * 30)`
     - Max 3 retries
  8. On terminal error (auth failure, invalid service, insufficient balance):
     - Log in `smm_connection_logs`
     - Transition to `failed` + record `OrderEvent`
     - Call `RefundOrderAction` with reason
     - Notify user: "سفارش شما ناموفق بود. مبلغ {amount_toman} تومان به کیف پول شما بازگشت."
     - Notify ops channel
- [ ] Implement `failed()` method on job: if max attempts exceeded, treat as terminal

### 4.7 Order Status Synchronization

- [ ] Implement `SyncOrderStatusAction`:
  1. Load order with its provider
  2. Call `adapter->getOrderStatus(provider_order_id)`
  3. Map external status to canonical `OrderStatus` using `ProviderStatusMapper`
  4. If status changed:
     - Update `orders.provider_status` (raw) and `orders.status` (canonical)
     - Record `OrderEvent` with `source=provider`
     - If `completed`: set `completed_at`
     - If `partial`: dispatch `ProcessPartialOrderAction` (calculate refund for undelivered)
     - If `cancelled`/`refunded` by provider: call `RefundOrderAction`
     - Notify user of meaningful status changes (not every poll)
  5. Log interaction in `smm_connection_logs`

- [ ] Implement `SyncOrderStatusJob`:
  1. Find all orders with `status IN ('submitted', 'processing')` older than 1 minute
  2. Group by provider to batch queries where possible
  3. For each order: call `SyncOrderStatusAction`
  4. Rate limit: max 30 requests per provider per minute
  5. On provider error: log but continue with next order
  6. Record cron run in `cron_runs` table

- [ ] Implement `ProcessPartialOrderAction`:
  1. If provider reports `partial` with `remains` count:
     - Calculate refund: `remains * price_per_1000 / 1000` (integer arithmetic)
     - Call `RefundOrderAction` with partial amount
     - Transition order to `partial`
     - Notify user with delivered/refunded breakdown

### 4.8 Provider Health Check

- [ ] Implement `ProviderHealthCheckJob`:
  1. Load all active providers
  2. For each: call `testConnection()`
  3. Update `smm_providers` health fields
  4. If provider down:
     - Increment `consecutive_failures`
     - If `consecutive_failures >= 3`: log warning to ops channel
     - If `consecutive_failures >= 5`: mark provider as `is_active = false`, notify ops
  5. If provider back up: reset `consecutive_failures`, notify ops
  6. Check provider balance: alert if below configurable threshold

### 4.9 Error Classification

- [ ] Create exception hierarchy:
  - `ProviderAuthenticationException` → Terminal. Provider token invalid.
  - `ProviderTimeoutException` → Retriable. Network or server timeout.
  - `ProviderApiException` → Inspect error code: service not found (terminal), insufficient balance (terminal), rate limited (retriable), server error (retriable).
  - `ProviderRateLimitException` → Retriable with longer backoff.
  - `ProviderUnavailableException` → Retriable. DNS failure, connection refused.
- [ ] Each adapter catches HTTP/parse errors and throws appropriate exception
- [ ] `SubmitSmmOrderJob` uses exception type to decide retry vs. fail

---

## 5. Jobs, Webhooks & Commands

### 5.1 Jobs

| Job | Queue | Trigger | Behavior |
|---|---|---|---|
| `SubmitSmmOrderJob` | `default` | After order placement (Phase 1, now enhanced) | Submit to real provider adapter. Retry on retriable errors with exponential backoff. Refund on terminal failure. |
| `SyncOrderStatusJob` | `sync` | Cron (every 3 min) | Poll providers for status updates on `submitted`/`processing` orders. Update canonical status. Notify users on changes. |
| `ProviderHealthCheckJob` | `low` | Cron (every 15 min) | Test connection for all active providers. Auto-disable after consecutive failures. Alert ops. |
| `NotifyOpsChannelJob` | `notifications` | SMM events | Notify ops channel on: submission failure, provider down, refund issued, partial order. |

### 5.2 Commands

| Command | Purpose |
|---|---|
| `smm:test-connection {provider_code}` | Manually test a specific provider's connectivity |
| `smm:sync-status {order_code?}` | Manually trigger status sync for one or all active orders |
| `smm:list-providers` | Display all providers with health status |

### 5.3 Scheduler Additions

```php
Schedule::job(new SyncOrderStatusJob)->everyThreeMinutes();
Schedule::job(new ProviderHealthCheckJob)->everyFifteenMinutes();
```

---

## 6. Testing Strategy

### 6.1 Unit Tests

| Test | What it verifies |
|---|---|
| `ProviderStatusMapperTest` | V1 status strings → canonical enum; V2 status strings → canonical enum; unknown status → `manual_review` + warning log |
| `V1ResponseParserTest` | Parse success: `{"order": 123}` → `ProviderOrderResult`; parse error: `{"error": "..."}` → exception |
| `V2ResponseParserTest` | Parse JSON responses with various HTTP status codes |
| `RedactsSecretsTest` | Token removed from logged URL; API key removed from request body; Authorization header removed |
| `SsrfProtectionTest` | Private IPs rejected (127.0.0.1, 10.x, 192.168.x); HTTP scheme rejected; only HTTPS allowed |
| `ErrorClassificationTest` | Timeout → retriable; auth error → terminal; 503 → retriable; invalid service → terminal |

### 6.2 Feature Tests

| Test | What it verifies |
|---|---|
| `SubmitRealOrderTest` | Order submitted via V1 adapter mock fixture → `submitted` status, `provider_order_id` saved |
| `SubmitOrderRetryTest` | First attempt timeout → job retried; second attempt succeeds → `submitted` |
| `SubmitOrderFinalFailureTest` | All retries exhausted → `failed`, wallet refunded, user notified |
| `SyncOrderStatusTest` | Provider returns `Completed` → order transitions to `completed`, user notified |
| `SyncPartialOrderTest` | Provider returns `Partial` with remains → partial refund calculated correctly (integer math), user notified |
| `ProviderHealthCheckTest` | Healthy provider → status updated; 3 consecutive failures → warning; 5 failures → deactivated |

### 6.3 Contract Tests

| Test | What it verifies |
|---|---|
| `V1ProviderAdapterTest` | **Fixture-based**: load JSON fixtures of real V1 API responses → assert parsing produces correct DTOs. Covers: `add` success/error, `status` all states, `balance`, `services` |
| `V2ProviderAdapterTest` | **Fixture-based**: load JSON fixtures of real V2 API responses → assert parsing produces correct DTOs. Covers: same scenarios with V2 format |
| `V1CreateOrderContractTest` | Request body matches V1 spec: `action=add&key=...&service=...&link=...&quantity=...` |
| `V2CreateOrderContractTest` | Request body matches V2 spec: JSON with correct field names |

### 6.4 Integration Tests

| Test | What it verifies |
|---|---|
| `SubmitSmmOrderJobIntegrationTest` | Full job lifecycle with mocked HTTP: dispatch → HTTP call → DB updates → event recording |
| `SyncOrderStatusJobIntegrationTest` | Cron job finds active orders → polls providers → updates statuses |
| `ProviderHealthCheckJobIntegrationTest` | Job tests providers → updates health fields → deactivates unhealthy |
| `ConnectionLogIntegrationTest` | Every adapter call creates a `smm_connection_logs` row with correct redaction |

---

## 7. Acceptance Criteria

| # | Criterion | Verification Method |
|---|---|---|
| AC-1 | V1 adapter can parse real fixture responses for `add`, `status`, `balance`, `services` | Contract tests pass |
| AC-2 | V2 adapter can parse real fixture responses for all supported actions | Contract tests pass |
| AC-3 | `createOrder()` sends correctly formatted request per API version | Contract test: verify request body |
| AC-4 | Provider token is encrypted in DB and never appears in logs, connection_logs, or Telegram messages | Security test: grep all log tables |
| AC-5 | SSRF protection: private IPs and HTTP schemes are rejected before any request is made | Unit test |
| AC-6 | Timeout error → job retries with exponential backoff (30s, 60s, 120s) | Integration test: verify release delays |
| AC-7 | Terminal error (auth failure) → order `failed`, wallet refunded, user notified within 1 job run | Feature test |
| AC-8 | `SyncOrderStatusJob` updates orders from `submitted` → `processing` → `completed` based on provider responses | Integration test |
| AC-9 | Partial order: provider reports `remains=50` on qty=100 → refund for 50 units calculated with integer math, credited to wallet | Feature test + DB verify |
| AC-10 | Provider health check: 5 consecutive failures → provider auto-deactivated → ops channel notified | Integration test |
| AC-11 | Every provider API call creates a `smm_connection_logs` row with redacted data | Integration test |
| AC-12 | `SyncOrderStatusJob` processes max 30 requests/provider/minute | Feature test with timer |
| AC-13 | Unknown provider status string → order goes to `manual_review` + ops notified | Unit test + feature test |
| AC-14 | `php artisan smm:test-connection mock_provider` returns health status | Manual CLI test |
| AC-15 | Order snapshot preserves original price/provider mapping — later catalog edits don't affect existing orders | Feature test: edit service price → old order unaffected |
