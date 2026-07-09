# Phase 1 (Vertical Slice) - Implementation Roadmap

## Completed Steps
- [x] **Step 1:** Core Shared Infrastructure & Database Layer (Migrations, Money Value Object, Core Enums).
- [x] **Step 2:** Telegram Webhook & Conversation Engine (Webhook Controller, TelegramApiService, StartHandler, MainMenuKeyboard).
- [x] **Step 3:** Catalog Module & Wallet Actions (Category/Service models, PriceCalculator, WalletAccount, WalletTransaction, Credit/Debit Wallet Actions with row locking).

## Pending Steps (To be executed next)
- [ ] **Step 4:** Mock SMM Integration (SmmProviderContract, MockProviderAdapter, ProviderFactory, DTOs).
- [ ] **Step 5:** Order Workflow & Purchase Conversation (PlaceOrderAction, RefundOrderAction, PurchaseConversation state machine, ConfirmOrderKeyboard).
- [ ] **Step 6:** Asynchronous Jobs & Commands (SubmitSmmOrderJob, Webhook Artisan Commands).
- [ ] **Step 7:** Phase 1 Final Polish & Verification.
