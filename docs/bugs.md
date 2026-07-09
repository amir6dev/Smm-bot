# Bug Tracker

This document strictly tracks all test failures, logical bugs, and architectural constraints missed during implementation, along with their resolutions.

---

### **Date:** 2026-07-09
- **Bug Description:** The `User::updateOrCreate()` method in `StartHandler` and `User::create()` in `WalletActionsTest` would fail with a `MassAssignmentException` because the default Laravel `App\Models\User` did not have `telegram_id`, `first_name`, `last_name`, `username`, and `last_active_at` in its `$fillable` array to match our custom `users` migration.
- **Affected Component:** `App\Models\User`
- **Resolution:** Overwrote the default `App\Models\User` to explicitly include all new migration fields in `$fillable`, added `$casts` for `last_active_at`, and mapped the `walletAccount()` relationship.

