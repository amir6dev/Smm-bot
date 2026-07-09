# Phase 6 — Deployment, Security Hardening & E2E Acceptance Tests

---

## 1. Phase Goal

Prepare the application for **production deployment on cPanel**, harden all security controls, implement log redaction, and execute a comprehensive end-to-end acceptance test checklist. By the end of this phase:

| Capability | Detail |
|---|---|
| cPanel deployment package | Complete deployment guide, directory structure, permissions, document root configuration |
| Installer command | `php artisan app:install` — prerequisite checks, migration, admin setup, webhook registration |
| Security hardening | APP_DEBUG=false, secret rotation docs, log redaction, IDOR checks, rate limiting |
| Log redaction | Middleware and service to strip tokens/keys/card numbers from all log channels |
| Cron configuration | Scheduler + queue worker via cPanel Cron Jobs |
| Backup strategy | Database + file backup documentation and verification |
| Health endpoint | `/health` with DB, queue, cron, and provider connectivity checks |
| E2E acceptance tests | Complete checklist covering all critical user and admin flows |
| Documentation package | README, DEPLOYMENT_CPANEL, INSTALLER_GUIDE, ADMIN_RUNBOOK, API_PROVIDER_PROFILES, PAYMENT_ADAPTERS, TEST_PLAN, SECURITY_CHECKLIST |

> **Architectural Constraints enforced in this phase:**
> - `APP_DEBUG=false` in production — **non-negotiable**.
> - No secrets (tokens, API keys, APP_KEY) in code, logs, Telegram messages, or public files.
> - `.env` and `vendor/` **never inside `public_html`**.
> - All existing financial constraints (BIGINT, append-only ledger, idempotency) verified by E2E tests.

---

## 2. Modules & Architecture

### 2.1 Directory Structure Affected

```
smm-telegram-bot/
├── app/
│   ├── Console/Commands/
│   │   ├── AppInstallCommand.php              # NEW
│   │   ├── AppHealthCheckCommand.php          # NEW
│   │   └── SecretRotateCommand.php            # NEW
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── HealthController.php           # NEW
│   │   └── Middleware/
│   │       ├── RedactSecretsMiddleware.php     # NEW
│   │       ├── SecurityHeadersMiddleware.php   # NEW
│   │       └── TrustedProxiesMiddleware.php    # Updated
│   │
│   ├── Modules/
│   │   └── Shared/
│   │       ├── Services/
│   │       │   ├── LogRedactionService.php     # NEW
│   │       │   └── HealthCheckService.php      # NEW
│   │       └── Logging/
│   │           └── RedactedFormatter.php       # NEW: custom Monolog formatter
│   │
│   └── Providers/
│       └── AppServiceProvider.php             # Updated: register middleware, logging
│
├── config/
│   ├── logging.php                            # Updated: redacted formatter
│   └── health.php                             # NEW
│
├── database/
│   └── seeders/
│       └── ProductionSeeder.php               # NEW: minimal production data
│
├── public/
│   ├── index.php
│   ├── .htaccess                              # Security rules
│   └── robots.txt
│
├── docs/
│   ├── README.md
│   ├── DEPLOYMENT_CPANEL.md                   # NEW
│   ├── INSTALLER_GUIDE.md                     # NEW
│   ├── ADMIN_RUNBOOK.md                       # NEW
│   ├── API_PROVIDER_PROFILES.md               # NEW
│   ├── PAYMENT_ADAPTERS.md                    # NEW
│   ├── TEST_PLAN.md                           # NEW
│   └── SECURITY_CHECKLIST.md                  # NEW
│
├── tests/
│   ├── E2E/
│   │   ├── UserRegistrationE2ETest.php        # NEW
│   │   ├── PurchaseFlowE2ETest.php            # NEW
│   │   ├── PaymentFlowE2ETest.php             # NEW
│   │   ├── AdminFlowE2ETest.php               # NEW
│   │   ├── ReferralFlowE2ETest.php            # NEW
│   │   └── BroadcastFlowE2ETest.php           # NEW
│   └── Security/
│       ├── SecretLeakageTest.php              # NEW
│       ├── IdorTest.php                       # NEW
│       ├── WebhookSpoofingTest.php            # NEW
│       ├── RateLimitTest.php                  # NEW
│       ├── AuthorizationTest.php              # NEW
│       └── InputInjectionTest.php             # NEW
│
├── storage/
│   └── app/
│       └── install.lock                       # Created after successful installation
│
└── .env.example                               # Updated: all keys documented
```

### 2.2 cPanel Deployment Layout

```
/home/ACCOUNT/
├── smm-bot-app/                    # Project root — NOT public
│   ├── app/
│   ├── config/
│   ├── vendor/                     # Composer dependencies
│   ├── storage/                    # Writable: logs, cache, sessions
│   │   ├── logs/
│   │   ├── framework/
│   │   │   ├── cache/
│   │   │   ├── sessions/
│   │   │   └── views/
│   │   └── app/
│   ├── bootstrap/
│   │   └── cache/                  # Writable: compiled config/routes
│   ├── database/
│   ├── .env                        # Production secrets — NEVER in public_html
│   └── public/                     # Only this is web-accessible
│       ├── index.php
│       ├── .htaccess
│       └── robots.txt
│
├── public_html/                    # cPanel document root
│   ├── index.php                   # Proxy to ../smm-bot-app/public/index.php
│   └── .htaccess                   # Security + rewrite rules
│
└── logs/                           # Scheduler and queue logs
    ├── scheduler.log
    └── queue.log
```

---

## 3. Database Schema

### 3.1 `cron_runs` (if not already created)

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `job_key` | `VARCHAR(100)` | `NOT NULL` | Job identifier |
| `started_at` | `TIMESTAMP` | `NOT NULL` | |
| `finished_at` | `TIMESTAMP` | `NULLABLE` | |
| `status` | `VARCHAR(20)` | `NOT NULL` | `running`, `success`, `failed` |
| `details_json` | `JSON` | `NULLABLE` | Result summary |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `idx_cr_job_key_started (job_key, started_at DESC)`, `idx_cr_status (status)`

### 3.2 `health_checks` (optional — alternative to cron_runs)

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `component` | `VARCHAR(50)` | `NOT NULL` | `database`, `queue`, `cron`, `smm_provider`, `webhook` |
| `status` | `VARCHAR(20)` | `NOT NULL` | `healthy`, `degraded`, `down` |
| `details_json` | `JSON` | `NULLABLE` | |
| `checked_at` | `TIMESTAMP` | `NOT NULL` | |

---

## 4. Actionable Tasks

### 4.1 Application Installer

- [ ] Implement `AppInstallCommand` (`php artisan app:install`):
  ```
  Step 1: Prerequisite checks
    ├── PHP version >= 8.4
    ├── Required extensions: curl, openssl, mbstring, pdo_mysql, tokenizer, xml, ctype, json, fileinfo, intl, bcmath, zip
    ├── Composer version >= 2.0
    ├── .env file exists and is readable
    ├── APP_KEY is set
    ├── Database connection works
    └── storage/ and bootstrap/cache/ are writable

  Step 2: Database setup
    ├── Run migrations: php artisan migrate --force
    └── Run seeders: php artisan db:seed --force

  Step 3: Initial admin setup
    ├── Prompt for super_admin Telegram ID
    └── Create admin_roles entry

  Step 4: Telegram webhook setup
    ├── Validate TELEGRAM_BOT_TOKEN is set
    ├── Validate TELEGRAM_WEBHOOK_URL is HTTPS
    ├── Register webhook with Telegram API
    ├── Verify with getWebhookInfo
    └── Send test /start to confirm

  Step 5: Finalization
    ├── Clear and cache config: php artisan config:cache
    ├── Cache routes: php artisan route:cache
    ├── Verify APP_DEBUG=false
    ├── Create storage/app/install.lock
    └── Display summary + next steps
  ```
- [ ] Installer must be **idempotent** — safe to run multiple times
- [ ] Create `storage/app/install.lock` after successful install → subsequent runs show warning
- [ ] **Never log tokens entered during installation**

### 4.2 Security Hardening

- [ ] Implement `LogRedactionService`:
  - Patterns to redact: API tokens, bot tokens, payment merchant IDs, OTP codes, card numbers, APP_KEY
  - Regex patterns: `/(token|key|secret|password|authorization|merchant)["\s:=]+[^\s"]{8,}/i` → replace with `[REDACTED]`
  - Card numbers: `/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/` → mask to `****-****-****-1234`
  - Phone numbers in logs: `/\+98\d{10}/` → mask to `+98****1234`

- [ ] Implement `RedactedFormatter` (custom Monolog formatter):
  - Wraps `LineFormatter`
  - Applies `LogRedactionService` to every log message and context
  - Registered as default formatter for all log channels in production

- [ ] Implement `SecurityHeadersMiddleware`:
  ```
  X-Content-Type-Options: nosniff
  X-Frame-Options: DENY
  X-XSS-Protection: 1; mode=block
  Referrer-Policy: strict-origin-when-cross-origin
  Content-Security-Policy: default-src 'self'
  Strict-Transport-Security: max-age=31536000; includeSubDomains
  ```

- [ ] Implement `RedactSecretsMiddleware`:
  - Applied to error responses (500 pages)
  - Strips stack traces in production
  - Replaces detailed errors with generic "خطای داخلی سرور" message

- [ ] Create `.htaccess` for `public/` and `public_html/`:
  ```apache
  # Deny access to dotfiles
  <FilesMatch "^\.">
      Require all denied
  </FilesMatch>

  # Deny access to sensitive file types
  <FilesMatch "\.(env|log|sql|bak|git|svn)$">
      Require all denied
  </FilesMatch>

  # Disable directory listing
  Options -Indexes

  # Laravel rewrite rules
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^ index.php [L]
  ```

- [ ] Create `public_html/index.php` proxy:
  ```php
  <?php
  // Proxy to Laravel application
  require __DIR__ . '/../smm-bot-app/public/index.php';
  ```

- [ ] Implement rate limiting configuration:
  | Endpoint/Action | Limit | Window |
  |---|---|---|
  | Telegram webhook | 60/min per IP | 1 min |
  | `/start` command per user | 5/min | 1 min |
  | OTP send | 3/hour per phone | 1 hour |
  | OTP verify | 5/10min per user | 10 min |
  | Invoice creation | 5/min per user | 1 min |
  | Order confirmation | 3/min per user | 1 min |
  | Status refresh | 1/min per order | 1 min |
  | Payment callback | 10/min per IP | 1 min |
  | Admin message to user | 5/min per admin | 1 min |

- [ ] Verify all IDOR protections:
  - Every user query scoped by `telegram_id`
  - Order queries: `->where('user_id', $user->id)`
  - Invoice queries: `->where('user_id', $user->id)`
  - Wallet queries: `->where('user_id', $user->id)`

### 4.3 Health Check Endpoint

- [ ] Implement `HealthController`:
  - Route: `GET /health`
  - Protected by health token in query string or IP allowlist
  - Returns JSON:
    ```json
    {
      "status": "healthy",
      "checks": {
        "database": { "status": "ok", "latency_ms": 5 },
        "queue": { "status": "ok", "pending_jobs": 3, "failed_jobs": 0, "oldest_pending_minutes": 2 },
        "cron": { "status": "ok", "last_run": "2026-07-09T12:05:00Z", "minutes_since": 5 },
        "webhook": { "status": "ok", "last_update": "2026-07-09T12:04:55Z" },
        "providers": {
          "panel_1": { "status": "healthy", "last_check": "...", "balance": 1500 }
        }
      },
      "version": "1.0.0",
      "environment": "production"
    }
    ```
  - **Never expose secrets, stack traces, or internal paths in health response**

- [ ] Implement `HealthCheckService`:
  - `checkDatabase()`: simple query + latency
  - `checkQueue()`: count pending/failed jobs, oldest pending age
  - `checkCron()`: latest `cron_runs` entry age
  - `checkWebhook()`: latest received `update_id` age
  - `checkProviders()`: latest health check results from `smm_providers`

### 4.4 cPanel Deployment Configuration

- [ ] Create Cron Job entries:
  ```
  * * * * * /usr/local/bin/php /home/ACCOUNT/smm-bot-app/artisan schedule:run >> /home/ACCOUNT/logs/scheduler.log 2>&1
  * * * * * /usr/local/bin/php /home/ACCOUNT/smm-bot-app/artisan queue:work database --once --tries=3 --timeout=60 >> /home/ACCOUNT/logs/queue.log 2>&1
  ```
- [ ] Verify directory permissions:
  ```
  storage/         → 775 (writable by PHP)
  bootstrap/cache/ → 775 (writable by PHP)
  .env             → 600 (owner read/write only)
  public/          → 755
  vendor/          → 755
  ```
- [ ] Verify `.env` production values:
  ```
  APP_ENV=production
  APP_DEBUG=false
  APP_URL=https://your-domain.com
  LOG_CHANNEL=daily
  LOG_LEVEL=warning
  QUEUE_CONNECTION=database
  CACHE_STORE=database
  SESSION_DRIVER=database
  ```
- [ ] Implement log rotation: daily logs with 14-day retention (`LOG_CHANNEL=daily` in config)

### 4.5 Secret Rotation Documentation

- [ ] Document `SecretRotateCommand` (`php artisan secret:rotate`):
  - Rotate `APP_KEY`: backup old key, generate new key, re-encrypt all encrypted columns
  - Rotate bot token: update `.env`, re-register webhook
  - Rotate payment tokens: update `payment_methods.config_encrypted_json`
  - Rotate SMM tokens: update `smm_providers.encrypted_token`, re-test connection
- [ ] Document rotation procedure in `SECURITY_CHECKLIST.md`

### 4.6 `.env.example` Final Version

- [ ] Update `.env.example` with all required keys (no real values):
  ```env
  APP_NAME="SMM Telegram Bot"
  APP_ENV=production
  APP_KEY=
  APP_DEBUG=false
  APP_TIMEZONE=Asia/Tehran
  APP_URL=https://your-domain.com

  DB_CONNECTION=mysql
  DB_HOST=localhost
  DB_PORT=3306
  DB_DATABASE=smm_bot
  DB_USERNAME=
  DB_PASSWORD=
  DB_CHARSET=utf8mb4
  DB_COLLATION=utf8mb4_unicode_ci

  QUEUE_CONNECTION=database
  CACHE_STORE=database
  SESSION_DRIVER=database

  LOG_CHANNEL=daily
  LOG_LEVEL=warning
  LOG_DAYS=14

  TELEGRAM_BOT_TOKEN=
  TELEGRAM_WEBHOOK_SECRET=
  TELEGRAM_WEBHOOK_PATH=
  TELEGRAM_WEBHOOK_URL=https://your-domain.com/telegram/webhook/${TELEGRAM_WEBHOOK_PATH}

  OTP_PROVIDER=melipayamak
  OTP_API_KEY=
  OTP_SENDER_NUMBER=

  HEALTH_CHECK_TOKEN=
  ```

---

## 5. Jobs, Webhooks & Commands

### 5.1 Commands

| Command | Purpose |
|---|---|
| `app:install` | Full installation wizard: prerequisites, migrations, admin, webhook |
| `app:health` | CLI health check — same as `/health` endpoint |
| `secret:rotate {type}` | Rotate APP_KEY, bot token, or payment/SMM tokens |
| `logs:redact-check` | Scan recent log files for any un-redacted secrets |
| `cron:verify` | Verify scheduler and queue worker are running (check `cron_runs` recency) |

### 5.2 Scheduler (Final — All Phases Combined)

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
```

---

## 6. Testing Strategy

### 6.1 E2E Acceptance Tests

These tests simulate complete user journeys from Telegram webhook to final state.

| Test | Scenario | Acceptance Criteria |
|---|---|---|
| `UserRegistrationE2ETest` | `/start` → user created → main menu shown | AC: `users` row exists, `wallet_accounts` created, main menu keyboard returned |
| `MandatoryJoinE2ETest` | `/start` with mandatory join enabled → blocked → join → verified → menu | AC: User blocked until channel membership confirmed |
| `ReferralRegistrationE2ETest` | `/start ref_{token}` → user created → referrer assigned → welcome gift credited | AC: `referrals` row, `wallet_transactions` credit, one-time only |
| `PurchaseFlowE2ETest` | Category → service → link input → quantity → confirm → wallet debited → job dispatched → submitted | AC: Single order, single debit, `provider_order_id` saved, user notified |
| `DuplicateConfirmationE2ETest` | Confirm button clicked twice → only one order, one debit | AC: Second click returns existing order message |
| `InsufficientBalanceE2ETest` | Attempt purchase with insufficient balance → rejected with "موجودی کافی نیست" | AC: No order created, no wallet transaction |
| `OnlinePaymentE2ETest` | Top-up → select gateway → enter amount → mock payment → callback → verify → wallet credited | AC: Invoice `paid`, one `wallet_transactions` credit, amount matches |
| `DuplicateCallbackE2ETest` | Same callback sent twice → only one credit | AC: One `wallet_transactions` row, second callback logged as `duplicate` |
| `CardToCardE2ETest` | Top-up → card-to-card → receipt sent → `manual_review` → operator approve → credited | AC: Invoice `paid` after approval, audit log with operator |
| `ManualPaymentRejectE2ETest` | Card-to-card → receipt → operator reject → `failed`, no credit | AC: Invoice `failed`, zero credit, user notified |
| `AdminAccessE2ETest` | Admin user sees admin menu; non-admin does not; revoked admin loses access | AC: Menu visibility matches role |
| `AdminCatalogE2ETest` | Create category → create service → activate → visible in purchase menu | AC: Full catalog CRUD from admin menu |
| `AdminBalanceAdjustE2ETest` | Admin credits 500 Toman → wallet updated → audit logged | AC: BIGINT Rial in DB, admin as actor |
| `BroadcastE2ETest` | Admin sends broadcast → batched delivery → blocked users marked → completion | AC: All deliveries tracked, blocked users flagged not deleted |
| `OrderStatusSyncE2ETest` | Order submitted → sync job → provider returns completed → order updated → user notified | AC: Status transition recorded in `order_events` |
| `RefundFlowE2ETest` | Order submission fails terminally → refund credited → user notified | AC: Refund `wallet_transactions`, order `failed`, idempotent |

### 6.2 Security Tests

| Test | Scenario | Verification |
|---|---|---|
| `SecretLeakageTest` | After running all flows: grep all log tables, `audit_logs`, `smm_connection_logs`, `payment_callbacks` for patterns matching API tokens, bot token, APP_KEY, merchant IDs, full card numbers | **Zero matches** |
| `LogRedactionTest` | Trigger a provider error with token in response → check log file | Token replaced with `[REDACTED]` |
| `IdorOrderTest` | User A requests User B's order by ID/code → rejected | 403 or empty result |
| `IdorInvoiceTest` | User A requests User B's invoice → rejected | 403 or empty result |
| `IdorWalletTest` | User A requests User B's wallet → rejected | 403 or empty result |
| `WebhookSpoofingTest` | POST to webhook URL without valid `X-Telegram-Bot-Api-Secret-Token` → 403 | Response code 403 |
| `WebhookReplayTest` | Same `update_id` sent twice → second is ignored | One-time processing only |
| `RateLimitOtpTest` | Send OTP 4 times within cooldown → 4th rejected | Rate limit error returned |
| `RateLimitInvoiceTest` | Create 6 invoices within 1 minute → 6th rejected | Rate limit error returned |
| `InputInjectionTest` | Service link input with `<script>`, SQL injection patterns, Markdown injection → sanitized or rejected | No injection in DB or Telegram output |
| `EnvExposureTest` | Request `/.env`, `/vendor/autoload.php`, `/storage/logs/laravel.log` via HTTP → 403/404 | No file content returned |
| `TemplateInjectionTest` | Message template with `@php`, `<?php`, `{{system('...')}}` → rejected | Validation error |
| `AuthorizationEscalationTest` | `support` role attempts `adjust_balance` → rejected | Permission denied |
| `DirectBalanceEditTest` | Attempt to `UPDATE wallet_accounts SET available_amount_rial = 999999` via model → exception | LogicException thrown |
| `ImmutableLedgerTest` | Attempt `UPDATE` or `DELETE` on `wallet_transactions` → exception | LogicException thrown |
| `FloatAmountTest` | Attempt to create order/invoice with float amount → rejected | Validation error; DB column is BIGINT |

### 6.3 Deployment Verification Tests

| Test | What it verifies |
|---|---|
| `MigrationFreshTest` | `php artisan migrate:fresh --seed` runs without errors on empty DB |
| `MigrationRollbackTest` | Each migration has a working `down()` method or documented reason for no rollback |
| `ConfigCacheTest` | `php artisan config:cache` succeeds; no closures in config files |
| `RouteCacheTest` | `php artisan route:cache` succeeds |
| `ProductionEnvTest` | `.env` has `APP_DEBUG=false`, `APP_ENV=production`, valid `APP_KEY` |
| `PublicDirectoryTest` | Only `index.php`, `.htaccess`, `robots.txt` in public directory |
| `FilePermissionTest` | `storage/` and `bootstrap/cache/` writable; `.env` not world-readable |
| `CronScheduleTest` | All scheduled jobs are registered and run at expected intervals |
| `QueueWorkerTest` | Queue worker processes and completes a test job |

---

## 7. Acceptance Criteria

### 7.1 Deployment Readiness

| # | Criterion | Verification |
|---|---|---|
| AC-1 | `php artisan app:install` completes successfully on fresh cPanel setup | Manual: run on clean environment |
| AC-2 | `php artisan migrate:fresh --seed` runs without errors | CI/local |
| AC-3 | Document root points to `public/` only; `.env`, `vendor/`, `storage/` not web-accessible | HTTP request test |
| AC-4 | `APP_DEBUG=false` in production `.env` | Config check |
| AC-5 | Cron jobs `schedule:run` and `queue:work --once` configured and executing | Check `cron_runs` table |
| AC-6 | Health endpoint returns correct status for all components | `curl /health?token=...` |

### 7.2 Security

| # | Criterion | Verification |
|---|---|---|
| AC-7 | Zero secrets (tokens, API keys, APP_KEY, merchant IDs) found in any log file, DB log table, or Telegram message | `SecretLeakageTest` |
| AC-8 | Card numbers masked as `****-****-****-1234` in all outputs | Security test |
| AC-9 | Phone numbers masked as `+98****1234` in logs | Security test |
| AC-10 | IDOR: user cannot access another user's orders, invoices, or wallet | `IdorTest` suite |
| AC-11 | Webhook spoofing: invalid secret token → 403 | `WebhookSpoofingTest` |
| AC-12 | Rate limiting active on all sensitive endpoints | `RateLimitTest` suite |
| AC-13 | `.env`, `/vendor/`, `/storage/logs/` return 403/404 via HTTP | `EnvExposureTest` |
| AC-14 | Template injection (Blade/PHP code in templates) → rejected | `TemplateInjectionTest` |

### 7.3 Financial Integrity

| # | Criterion | Verification |
|---|---|---|
| AC-15 | All monetary columns in DB use `BIGINT` — verified by `SHOW COLUMNS` on all financial tables | Migration review + DB query |
| AC-16 | `wallet_transactions` table has no `UPDATE` or `DELETE` capability | `ImmutableLedgerTest` |
| AC-17 | Duplicate payment callback → exactly one wallet credit | `DuplicateCallbackE2ETest` |
| AC-18 | Duplicate order confirmation → exactly one order + one debit | `DuplicateConfirmationE2ETest` |
| AC-19 | Failed order submission → wallet refund with separate ledger entry | `RefundFlowE2ETest` |
| AC-20 | Float amounts rejected at input validation | `FloatAmountTest` |

### 7.4 Functional Completeness

| # | Criterion | Verification |
|---|---|---|
| AC-21 | `/start` → register → main menu works | E2E test |
| AC-22 | Full purchase flow: select → input → confirm → debit → submit → track | E2E test |
| AC-23 | Online payment: create invoice → pay → callback → verify → credit | E2E test |
| AC-24 | Card-to-card: create → receipt → review → approve/reject | E2E test |
| AC-25 | Referral: link → register → one-time assignment → rewards | E2E test |
| AC-26 | Admin: menu → RBAC → catalog CRUD → balance adjust → broadcast | E2E test |
| AC-27 | Feature flags: disable purchases → user rejected → in-flight orders continue | E2E test |
| AC-28 | Status sync: cron polls providers → orders updated → users notified | E2E test |

### 7.5 Documentation Completeness

| # | Criterion | Verification |
|---|---|---|
| AC-29 | `README.md` covers: local setup, requirements, commands, structure, security principles | Review |
| AC-30 | `DEPLOYMENT_CPANEL.md` covers: step-by-step deployment, paths, permissions, cron, webhook, rollback | Review |
| AC-31 | `ADMIN_RUNBOOK.md` covers: catalog, providers, payments, manual orders, broadcasts, troubleshooting | Review |
| AC-32 | `API_PROVIDER_PROFILES.md` covers: adding V1/V2 provider, fixtures, contract tests, status mapping | Review |
| AC-33 | `SECURITY_CHECKLIST.md` covers: production settings, secret rotation, backup, log hygiene, testing | Review |
| AC-34 | `TEST_PLAN.md` covers: all test suites, test data, roles, results, acceptance criteria | Review |

### 7.6 Quality Gate

| # | Criterion | Verification |
|---|---|---|
| AC-35 | All tests pass: `php artisan test` exits 0 | CI/local |
| AC-36 | No Critical or High severity issues remain open | Security test results |
| AC-37 | At least one V1 and one V2 provider has fixture + contract test | File check in `tests/Contract/` |
| AC-38 | All routes tested with HTTPS + production headers | Integration test |
| AC-39 | Medium/Low issues have documented risk and remediation plan | `SECURITY_CHECKLIST.md` |
