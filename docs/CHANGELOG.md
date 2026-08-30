# Changelog

## 2026-07-09
- **Phase 1 (Vertical Slice) Initialization**: Created Core Shared Infrastructure and Database Layer.
- **Value Objects**: Added `App\Modules\Shared\ValueObjects\Money` to enforce BIGINT Rial currency operations and integer math across the platform, preventing float manipulation.
- **Enums**: 
  - `OrderStatus`
  - `TransactionType`
  - `ActorType`
- **Migrations**: Implemented schemas for `users`, `wallet_accounts` (with versioning for optimistic locking), `wallet_transactions` (append-only), `orders`, and `order_events` (append-only) adhering to the strict immutability and schema rules outlined in Phase 1 and Phase 7.
- **Phase 1 (Vertical Slice) Step 2**: Telegram Webhook & Conversation Engine.
- **Services**: Built `App\Modules\Telegram\Services\TelegramApiService` wrapping Laravel's Http client to handle Telegram Bot API calls (`sendMessage`, `getWebhookInfo`, `setWebhook`) safely.
- **Controllers**: Implemented `App\Modules\Telegram\Controllers\TelegramWebhookController` to validate the `X-Telegram-Bot-Api-Secret-Token`, parse incoming updates, and route to handlers. Ensures a 200 OK is returned immediately, adhering to async constraints.
- **Handlers**: Created `StartHandler` for automatic user registration (via `updateOrCreate()`) and zero-balance wallet creation upon the first `/start` interaction.
- **Conversations & Keyboards**: Setup `ConversationManager` and implemented `MainMenuKeyboard` with the requested options.
- **Code Review Fixes (Architecture Checkpoint)**: 
  - Added Eloquent models `WalletTransaction` and `OrderEvent` that explicitly throw `LogicException` on `update()`, `delete()`, `destroy()`, and `forceDelete()` to strictly enforce the Immutable Ledger constraint.
  - Refactored `StartHandler` to dispatch `SendTelegramMessageJob` instead of synchronously calling `TelegramApiService->sendMessage()`, ensuring the webhook controller is fully asynchronous and doesn't hang.
- **Phase 1 (Vertical Slice) Step 3**: Catalog Module and Wallet Actions.
- **Catalog Models & Services**: Implemented `Category` and `Service` models with active scopes, proper property casting, and relationships. Built `CatalogQueryService` for querying active offerings, and `PriceCalculator` for computing prices using strict integer arithmetic (`ceil`).
- **Wallet Models & Exceptions**: Added `WalletAccount` mapped with relationships and version locking field. Overwrote `WalletTransaction` to lock it with `$guarded`, enum casting, and exception throwing on updates/deletes to enforce ledger append-only constraints. Added `InsufficientBalanceException`.
- **Wallet Actions**: Built transactional `CreditWalletAction` and `DebitWalletAction`. These actions explicitly wrap operations in `DB::transaction()`, use `->lockForUpdate()` on the wallet account for concurrency control, check for duplicate operations via `idempotency_key`, and accurately recalculate balances before persisting the updated version and ledger entry.
- **Automated Tests for Step 3**:
  - `PriceCalculatorTest`: Validated strict integer arithmetic, proper rounding (`ceil`/`floor`), and boundary limits for affordable quantity calculations.
  - `WalletTransactionImmutabilityTest`: Verified that exceptions are rigorously thrown when attempting to update or delete `WalletTransaction` records.
  - `WalletActionsTest`: Feature tested DB transaction integrity, row-locking (simulated implicitly), `InsufficientBalanceException`, and successful implementation of idempotency across Credit and Debit actions.

## 2026-08-30
- **Phase 1 (Vertical Slice) Step 4**: Mock SMM Integration.
- **SMM DTOs**:
  - `App\Modules\Smm\DTOs\ProviderOrderResult`: Immutable `readonly` DTO encapsulating provider order creation results (`isSuccess`, `providerOrderId`, `errorMessage`) with named constructors `success()` and `failure()` for clean instantiation.
  - `App\Modules\Smm\DTOs\ProviderOrderStatusResult`: Immutable `readonly` DTO encapsulating order status query results. Status is mapped to the internal `OrderStatus` enum by the adapter, ensuring no raw provider strings leak into the application layer.
- **SMM Contract**: Created `App\Modules\Smm\Contracts\SmmProviderContract` interface defining `createOrder()`, `getOrderStatus()`, and `getBalance()` — the uniform API surface all provider adapters (Mock, V1, V2) must implement.
- **Mock Adapter**: Implemented `App\Modules\Smm\Adapters\MockProviderAdapter` satisfying `SmmProviderContract`. Simulates order creation with `MOCK-XXXXX` IDs, random `PROCESSING`/`COMPLETED` status responses, and a fixed `100000` balance. Uses `usleep(100_000)` for minimal latency simulation without slowing tests.
- **Provider Factory**: Built `App\Modules\Smm\Factories\SmmProviderFactory` with a static `make(string $providerCode)` method using `match()`. Currently defaults to `MockProviderAdapter` for all codes. Structured for Phase 3 extensibility — real provider adapters will be added as additional match arms.
