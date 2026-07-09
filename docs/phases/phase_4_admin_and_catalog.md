# Phase 4 — Admin Panel, Catalog Management, RBAC & Feature Flags

---

## 1. Phase Goal

Deliver the **complete in-bot admin management system** including role-based access control, full catalog CRUD, provider management, user administration, payment method configuration, text template editing, channel routing, and feature flags. By the end of this phase:

| Capability | Detail |
|---|---|
| Admin menu in Telegram | Full admin inline keyboard menu accessible only to authorized users |
| RBAC system | Roles: `super_admin`, `product_manager`, `financial_operator`, `order_operator`, `support`; permission checks on every admin action |
| Catalog CRUD | Create/edit/reorder/activate categories, subcategories, and services — all from within the bot |
| Provider management | Add/edit/test/activate SMM providers via admin menu |
| Payment method management | Configure gateways, card-to-card, activation, ordering |
| User administration | Search, view profile, adjust balance, ban/unban, send message |
| Text templates | Manage bot messages (start, OTP, payment, order) with whitelisted variables |
| Channel configuration | Set destination channels for each event type |
| Feature flags | Toggle: bot on/off, purchases, payments, OTP, gifts, mandatory join, each gateway |
| Settings management | Support contact, admin list, operational keys |
| Audit logging | Every admin action recorded with actor, before/after state |

> **Architectural Constraints enforced in this phase:**
> - Balance adjustments use the **append-only wallet ledger** — never direct DB edits.
> - All admin identity checks use **Telegram ID whitelist + RBAC** — not username.
> - Text templates use **variable whitelist** only (`{{var_name}}`). No Blade/code execution.
> - Config secrets (tokens, keys) stored **encrypted**; shown masked in admin UI.

---

## 2. Modules & Architecture

### 2.1 Directory Structure Affected

```
app/Modules/
├── Admin/
│   ├── Models/
│   │   ├── AdminRole.php
│   │   ├── Permission.php
│   │   ├── Setting.php
│   │   ├── FeatureFlag.php
│   │   ├── MessageTemplate.php
│   │   └── Channel.php
│   ├── Enums/
│   │   ├── Role.php
│   │   ├── PermissionName.php
│   │   └── FeatureFlagKey.php
│   ├── Services/
│   │   ├── RbacService.php
│   │   ├── FeatureFlagService.php
│   │   ├── SettingsService.php
│   │   ├── MessageTemplateService.php
│   │   └── ChannelService.php
│   ├── Actions/
│   │   ├── GrantRoleAction.php
│   │   ├── RevokeRoleAction.php
│   │   ├── AdjustUserBalanceAction.php
│   │   ├── BanUserAction.php
│   │   ├── UnbanUserAction.php
│   │   └── SendAdminMessageAction.php
│   ├── Guards/
│   │   └── AdminGuard.php
│   └── Providers/
│       └── AdminServiceProvider.php
│
├── Telegram/
│   ├── Handlers/
│   │   ├── Admin/
│   │   │   ├── AdminMenuHandler.php
│   │   │   ├── AdminBroadcastHandler.php       # Stub → Phase 5
│   │   │   ├── AdminStatsHandler.php           # Stub → Phase 5
│   │   │   ├── AdminProviderHandler.php
│   │   │   ├── AdminSettingsHandler.php
│   │   │   ├── AdminUserHandler.php
│   │   │   ├── AdminChannelHandler.php
│   │   │   ├── AdminGatewayHandler.php
│   │   │   ├── AdminCatalogHandler.php
│   │   │   ├── AdminTextHandler.php
│   │   │   ├── AdminGiftHandler.php            # Stub → Phase 5
│   │   │   └── AdminFeatureFlagHandler.php
│   │   └── MandatoryJoinHandler.php            # NEW
│   ├── Conversations/
│   │   ├── Admin/
│   │   │   ├── CreateCategoryConversation.php
│   │   │   ├── EditCategoryConversation.php
│   │   │   ├── CreateServiceConversation.php
│   │   │   ├── EditServiceConversation.php
│   │   │   ├── AddProviderConversation.php
│   │   │   ├── EditProviderConversation.php
│   │   │   ├── AddGatewayConversation.php
│   │   │   ├── EditGatewayConversation.php
│   │   │   ├── UserSearchConversation.php
│   │   │   ├── AdjustBalanceConversation.php
│   │   │   ├── EditTextConversation.php
│   │   │   ├── SetChannelConversation.php
│   │   │   └── GrantRoleConversation.php
│   │   └── MandatoryJoinConversation.php
│   └── Keyboards/
│       └── Admin/
│           ├── AdminMainMenuKeyboard.php
│           ├── CatalogMenuKeyboard.php
│           ├── CategoryListKeyboard.php
│           ├── ServiceListKeyboard.php
│           ├── ProviderListKeyboard.php
│           ├── GatewayListKeyboard.php
│           ├── UserActionsKeyboard.php
│           ├── FeatureFlagListKeyboard.php
│           ├── TextTemplateListKeyboard.php
│           └── ChannelListKeyboard.php
│
├── Catalog/   # Extended from Phase 1
│   └── Actions/
│       ├── CreateCategoryAction.php
│       ├── UpdateCategoryAction.php
│       ├── ReorderCategoryAction.php
│       ├── CreateServiceAction.php
│       ├── UpdateServiceAction.php
│       └── ReorderServiceAction.php

database/migrations/
├── 0019_create_admin_roles_table.php
├── 0020_create_permissions_table.php
├── 0021_create_settings_table.php
├── 0022_create_feature_flags_table.php
├── 0023_create_message_templates_table.php
├── 0024_create_channels_table.php
└── 0025_add_admin_seeder_data.php

database/seeders/
├── RolePermissionSeeder.php
├── FeatureFlagSeeder.php
├── MessageTemplateSeeder.php
└── ChannelSeeder.php
```

### 2.2 Admin Menu Structure

```
📊 منوی مدیریت
├── 📢 پیام همگانی        → Phase 5
├── 📈 آمار ربات           → Phase 5
├── 🌐 وب‌سرویس            → AdminProviderHandler
├── ⚙️ تنظیمات             → AdminSettingsHandler
├── 👤 مشخصات کاربر        → AdminUserHandler
├── 📡 کانال               → AdminChannelHandler
├── 💳 درگاه               → AdminGatewayHandler
├── 📦 محصولات             → AdminCatalogHandler
├── 📝 متن‌ها              → AdminTextHandler
├── 🎁 هدایا               → Phase 5
└── 🔘 روشن/خاموش          → AdminFeatureFlagHandler
```

---

## 3. Database Schema

### 3.1 `admin_roles`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `user_id` | `BIGINT UNSIGNED` | `NOT NULL FK → users.id` | |
| `role` | `VARCHAR(30)` | `NOT NULL` | `super_admin`, `product_manager`, `financial_operator`, `order_operator`, `support` |
| `granted_by` | `BIGINT UNSIGNED` | `NULLABLE FK → users.id` | Who assigned this role |
| `granted_at` | `TIMESTAMP` | `NOT NULL` | |
| `revoked_at` | `TIMESTAMP` | `NULLABLE` | Soft-revoke instead of delete |
| `revoked_by` | `BIGINT UNSIGNED` | `NULLABLE FK → users.id` | |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `idx_ar_user_role (user_id, role, revoked_at)`, `idx_ar_role_active (role, revoked_at)`

> Revoke sets `revoked_at` — historical roles are preserved for audit trail.

### 3.2 `permissions`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `role` | `VARCHAR(30)` | `NOT NULL` | Role this permission belongs to |
| `permission` | `VARCHAR(100)` | `NOT NULL` | Permission name from `PermissionName` enum |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |

**Indexes:** `UNIQUE idx_perm_role_perm (role, permission)`

### 3.3 `settings`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `key` | `VARCHAR(100)` | `UNIQUE NOT NULL` | Setting identifier |
| `value_json` | `JSON` | `NOT NULL` | Setting value |
| `description` | `VARCHAR(500)` | `NULLABLE` | Human-readable description |
| `is_encrypted` | `BOOLEAN` | `NOT NULL DEFAULT FALSE` | If true, `value_json` is encrypted |
| `updated_by` | `BIGINT UNSIGNED` | `NULLABLE FK → users.id` | Last editor |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

### 3.4 `feature_flags`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `key` | `VARCHAR(100)` | `UNIQUE NOT NULL` | Flag identifier |
| `name` | `VARCHAR(255)` | `NOT NULL` | Display name (Farsi) |
| `description` | `VARCHAR(500)` | `NULLABLE` | |
| `is_enabled` | `BOOLEAN` | `NOT NULL DEFAULT FALSE` | |
| `updated_by` | `BIGINT UNSIGNED` | `NULLABLE FK → users.id` | |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Seeded flags:** `bot_enabled`, `purchases_enabled`, `payments_enabled`, `mandatory_join_enabled`, `otp_enabled`, `gift_enabled`, `referral_enabled`, `support_enabled`, `gateway_{driver}_enabled` (per gateway)

### 3.5 `message_templates`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `key` | `VARCHAR(100)` | `UNIQUE NOT NULL` | Template identifier |
| `name` | `VARCHAR(255)` | `NOT NULL` | Display name |
| `body` | `TEXT` | `NOT NULL` | Template text with `{{variable}}` placeholders |
| `allowed_variables_json` | `JSON` | `NOT NULL` | Whitelist of allowed variable names |
| `updated_by` | `BIGINT UNSIGNED` | `NULLABLE FK → users.id` | |
| `version` | `INT UNSIGNED` | `NOT NULL DEFAULT 1` | Incremented on each edit |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Seeded templates:** `start_message`, `otp_message`, `card_to_card_instructions`, `payment_success`, `order_placed`, `order_completed`, `order_failed`, `refund_message`, `welcome_gift`

### 3.6 `channels`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AI` | `PRIMARY KEY` | |
| `purpose` | `VARCHAR(50)` | `UNIQUE NOT NULL` | Event type this channel handles |
| `chat_id` | `VARCHAR(50)` | `NOT NULL` | Telegram chat_id (channel/group) |
| `username` | `VARCHAR(100)` | `NULLABLE` | @channel_username |
| `name` | `VARCHAR(255)` | `NULLABLE` | Display name |
| `is_active` | `BOOLEAN` | `NOT NULL DEFAULT TRUE` | |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | |

**Purpose values:** `transactions`, `card_to_card_review`, `manual_orders`, `smm_orders`, `otp_logs`, `errors`, `support`, `success_notifications`, `mandatory_join`

---

## 4. Actionable Tasks

### 4.1 RBAC System

- [ ] Define `Role` Enum: `super_admin`, `product_manager`, `financial_operator`, `order_operator`, `support`
- [ ] Define `PermissionName` Enum with all permission strings:
  ```
  manage_categories, manage_services, manage_providers, manage_gateways,
  manage_users, adjust_balance, ban_user, manage_settings, manage_templates,
  manage_channels, manage_feature_flags, manage_roles, manage_broadcasts,
  view_stats, view_orders, view_payments, send_user_message, approve_manual_payment
  ```
- [ ] Create migration: `admin_roles` (§3.1)
- [ ] Create migration: `permissions` (§3.2)
- [ ] Create `RolePermissionSeeder`:
  - `super_admin` → all permissions
  - `product_manager` → `manage_categories, manage_services, manage_providers, manage_templates, view_orders, view_stats`
  - `financial_operator` → `view_payments, approve_manual_payment, adjust_balance, view_orders`
  - `order_operator` → `view_orders, manage_providers, send_user_message`
  - `support` → `view_orders, view_payments, send_user_message`
- [ ] Implement `RbacService`:
  - `hasRole(User, Role): bool`
  - `hasPermission(User, PermissionName): bool`
  - `isAdmin(User): bool` — has any active role
  - `getActiveRoles(User): Collection`
- [ ] Implement `AdminGuard`: middleware-like check called at the start of every admin handler
- [ ] Implement `GrantRoleAction` + `RevokeRoleAction` with audit logging
- [ ] Create artisan command: `admin:grant {telegram_id} {role}` for initial setup

### 4.2 Feature Flags

- [ ] Define `FeatureFlagKey` Enum with all flag keys
- [ ] Create migration: `feature_flags` (§3.4)
- [ ] Create `FeatureFlagSeeder` with default values (all enabled for dev)
- [ ] Implement `FeatureFlagService`:
  - `isEnabled(string $key): bool` — cached for 60s
  - `toggle(string $key, User $actor): void` — with audit log
  - `getAll(): Collection`
- [ ] Integrate feature flags into existing flows:
  - `bot_enabled` → checked in webhook controller; if off, respond "ربات موقتاً غیرفعال است"
  - `purchases_enabled` → checked before purchase conversation starts
  - `payments_enabled` → checked before top-up conversation starts
  - `mandatory_join_enabled` → checked in StartHandler
  - `otp_enabled` → checked in TopUpConversation
- [ ] **Critical**: Disabling a flag stops NEW actions only. In-flight orders/payments continue processing via jobs.

### 4.3 Settings & Templates

- [ ] Create migration: `settings` (§3.3)
- [ ] Create migration: `message_templates` (§3.5)
- [ ] Create migration: `channels` (§3.6)
- [ ] Implement `SettingsService`: get/set with cache, encrypted value support
- [ ] Implement `MessageTemplateService`:
  - `render(string $key, array $variables): string`
  - Only `{{variable_name}}` syntax allowed — validate against `allowed_variables_json`
  - **Never execute Blade or PHP code in templates**
  - Escape HTML entities in variable values to prevent Telegram Markdown injection
- [ ] Implement `ChannelService`: resolve channel for purpose, validate bot access
- [ ] Create seeders for default templates and channels

### 4.4 Catalog Management (Admin)

- [ ] Implement `AdminCatalogHandler`:
  - Show menu: دسته‌بندی‌ها | سرویس‌ها
  - Category CRUD: list → select → view/edit/create/reorder/toggle active
  - Service CRUD: select category → list services → select → view/edit/create/reorder/toggle
- [ ] Implement `CreateCategoryConversation`:
  - Step 1: Enter name
  - Step 2: Enter code (slug, validated unique)
  - Step 3: Enter emoji (optional)
  - Step 4: Select parent category (optional, for subcategory)
  - Step 5: Confirm → create
- [ ] Implement `EditCategoryConversation`: edit name, emoji, sort_order, is_active, is_visible
- [ ] Implement `CreateServiceConversation`:
  - Step 1: Select category
  - Step 2: Select SMM provider
  - Step 3: Enter provider_service_id
  - Step 4: Enter title, description
  - Step 5: Enter price per 1000 (Toman → stored as Rial)
  - Step 6: Enter cost per 1000 (Toman → stored as Rial) for margin tracking
  - Step 7: Enter min/max quantity
  - Step 8: Define input schema (link, username, etc.)
  - Step 9: Confirm → create (initially `is_active=false`)
- [ ] Implement `EditServiceConversation`: edit price, limits, description, toggle active
- [ ] Implement `ReorderCategoryAction` + `ReorderServiceAction`: swap sort_order values
- [ ] **Validation**: service cannot be activated without valid provider mapping

### 4.5 Provider Management (Admin)

- [ ] Implement `AdminProviderHandler`:
  - List providers with health status indicator (🟢/🟡/🔴)
  - Select provider → view details + actions
  - Actions: edit, test connection, view balance, toggle active, view last error
- [ ] Implement `AddProviderConversation`:
  - Step 1: Enter name
  - Step 2: Enter code (unique slug)
  - Step 3: Enter base URL (validated HTTPS)
  - Step 4: Select API profile (v1, v2, custom)
  - Step 5: Enter API token (shown only once, stored encrypted)
  - Step 6: Configure timeout and retry settings
  - Step 7: Confirm → create (initially `is_active=false`)
  - Step 8: Auto-run test connection
- [ ] Implement `EditProviderConversation`:
  - Edit name, URL, profile, timeout → audit logged
  - Token change: require re-entry → encrypt → run test connection
  - **Token never re-displayed**
- [ ] Test connection result shown inline with latency and balance

### 4.6 User Administration

- [ ] Implement `AdminUserHandler`:
  - Search: enter Telegram ID or phone number
  - Display profile: name, ID, balance, orders count, payments total, referrals, join date, status, last active
  - Phone/card: shown masked unless operator has `financial_operator` or `super_admin` role
- [ ] Implement `AdjustBalanceConversation`:
  - Step 1: Enter amount (positive for credit, negative for debit) in Toman
  - Step 2: Enter mandatory reason
  - Step 3: Enter optional reference code
  - Step 4: Confirm with double-confirmation ("آیا مطمئن هستید؟")
  - Step 5: Execute via `CreditWalletAction` or `DebitWalletAction` with admin as actor
  - **Uses append-only ledger — never direct balance edit**
  - Audit log records admin ID, before/after balance, reason
- [ ] Implement `BanUserAction`: set `users.status = 'banned'`, log reason, notify user
- [ ] Implement `UnbanUserAction`: set `users.status = 'active'`, log, notify
- [ ] Implement `SendAdminMessageAction`: send message to user with rate limit + audit log

### 4.7 Gateway Management (Admin)

- [ ] Implement `AdminGatewayHandler`:
  - List payment methods with status (🟢/🔴)
  - Select → view details + actions
  - Toggle active, reorder
- [ ] Implement `AddGatewayConversation`:
  - Enter driver (from allowlist), name, config (encrypted), min/max amounts, OTP requirement
- [ ] Implement `EditGatewayConversation`: edit config, amounts, ordering
- [ ] Gateway config shown masked (e.g., `merchant: zarinpal-****-xxxx`)

### 4.8 Mandatory Join

- [ ] Implement `MandatoryJoinHandler`:
  - When `mandatory_join_enabled` and user not member of required channels
  - Show channel links + "بررسی عضویت" button
  - On click: dispatch `CheckMandatoryJoinJob` (Phase 1 scheduler placeholder)
  - If verified: show main menu
  - Channels configured via admin → `channels` table with `purpose = 'mandatory_join'`

### 4.9 Text Template Editing

- [ ] Implement `AdminTextHandler`:
  - List templates by key
  - Select → show current body + allowed variables
  - Edit body: validate only whitelisted `{{variables}}` used
  - Preview rendered with sample data
  - Version incremented on save

### 4.10 Channel Configuration

- [ ] Implement `AdminChannelHandler`:
  - List channel purposes
  - Set/change chat_id for each purpose
  - Test: send test message to channel, verify bot has send permission
  - Toggle active per channel

---

## 5. Jobs, Webhooks & Commands

### 5.1 Jobs

| Job | Queue | Trigger | Behavior |
|---|---|---|---|
| `CheckMandatoryJoinJob` | `default` | User clicks "بررسی عضویت" | Call `getChatMember` for each required channel. If all joined → send main menu. Rate limited per user. |

### 5.2 Commands

| Command | Purpose |
|---|---|
| `admin:grant {telegram_id} {role}` | Grant admin role (for initial setup without existing admin) |
| `admin:revoke {telegram_id} {role}` | Revoke admin role |
| `admin:list` | List all active admins with roles |
| `settings:get {key}` | View a setting value |
| `settings:set {key} {value}` | Set a setting value |
| `flags:list` | List all feature flags with status |
| `flags:toggle {key}` | Toggle a feature flag |

---

## 6. Testing Strategy

### 6.1 Unit Tests

| Test | What it verifies |
|---|---|
| `RbacServiceTest` | `super_admin` has all permissions; `support` cannot `adjust_balance`; revoked role has no permissions |
| `FeatureFlagServiceTest` | Flag enabled → returns true; disabled → returns false; caching works; toggle audits |
| `MessageTemplateServiceTest` | Valid variables rendered; unknown variables rejected; HTML entities escaped; Blade code rejected |
| `RoleEnumTest` | All roles have corresponding permission sets |
| `PermissionEnumTest` | No duplicate permission names; all admin handlers have corresponding permissions |

### 6.2 Feature Tests

| Test | What it verifies |
|---|---|
| `AdminMenuAccessTest` | Admin sees admin menu; non-admin does not; revoked admin cannot access |
| `AdminRbacTest` | `product_manager` can create category but not adjust balance; `financial_operator` can adjust but not create category |
| `CreateCategoryTest` | Category created with correct fields; duplicate code rejected; subcategory parent validated |
| `CreateServiceTest` | Service created with Rial price; inactive by default; invalid provider rejected |
| `EditServicePriceTest` | Price updated; existing orders' snapshot unchanged |
| `AdjustBalanceTest` | Admin credits 1000 Toman → wallet_transaction created with `actor_type=admin`; balance correct; audit logged |
| `BanUserTest` | Banned user cannot purchase or top up; existing order jobs continue |
| `FeatureFlagToggleTest` | Disabling `purchases_enabled` → new purchase rejected; in-flight order continues |
| `MandatoryJoinTest` | User not in channel → blocked from main menu; after joining → access granted |
| `TextTemplateEditTest` | Template body updated; disallowed variable rejected; version incremented |
| `ProviderAddTest` | Provider created encrypted; test connection auto-runs; token not visible in responses |

### 6.3 Security Tests

| Test | What it verifies |
|---|---|
| `AdminImpersonationTest` | Changing username does not grant admin access (Telegram ID used) |
| `TemplateInjectionTest` | Template with `{{__('hacked')}}`, `@php`, `<?php` → rejected |
| `BalanceDirectEditTest` | Attempting to UPDATE `wallet_accounts.available_amount_rial` directly throws exception |
| `SettingsEncryptionTest` | `is_encrypted=true` settings stored encrypted; decrypted only on read |

---

## 7. Acceptance Criteria

| # | Criterion | Verification Method |
|---|---|---|
| AC-1 | Admin user (by Telegram ID) sees "📊 منوی مدیریت" button; non-admin does not | Manual + feature test |
| AC-2 | `product_manager` can create/edit categories and services but cannot access financial operations | Feature test |
| AC-3 | `financial_operator` can approve card-to-card and adjust balance but cannot edit catalog | Feature test |
| AC-4 | Category created via admin menu appears in user's purchase flow | Manual: create category → verify in "خرید سرویس" |
| AC-5 | Service with `is_active=false` or without valid provider mapping does not appear in purchase menu | Feature test |
| AC-6 | Admin balance adjustment: amount entered in Toman, stored as Rial BIGINT, wallet_transaction created as append-only | DB verify |
| AC-7 | Balance adjustment records admin Telegram ID as actor in both `wallet_transactions` and `audit_logs` | DB verify |
| AC-8 | Banned user receives "حساب شما مسدود شده است" for any financial action; existing jobs continue | Feature test |
| AC-9 | Feature flag `purchases_enabled=false` → user gets "خرید موقتاً غیرفعال است"; submitted orders still processed by jobs | Feature test |
| AC-10 | Provider token entered by admin is encrypted in DB; UI shows "توکن: ****" | DB + manual verify |
| AC-11 | Text template with `{{user_name}}` renders correctly; template with `@php echo 1; @endphp` is rejected | Unit test |
| AC-12 | Channel routing: payment events go to `transactions` channel; errors go to `errors` channel | Integration test |
| AC-13 | Mandatory join: user not in channel → cannot access menu; "بررسی عضویت" → verified → access granted | Manual flow |
| AC-14 | `php artisan admin:grant 123456789 super_admin` creates admin role in DB | CLI test |
| AC-15 | Every admin action creates an `audit_logs` entry with correct actor, action, before/after | DB audit check |
