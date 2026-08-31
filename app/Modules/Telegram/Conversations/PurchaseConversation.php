<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Conversations;

use App\Jobs\SendTelegramMessageJob;
use App\Models\User;
use App\Modules\Catalog\Services\CatalogQueryService;
use App\Modules\Catalog\Services\PriceCalculator;
use App\Modules\Orders\Actions\PlaceOrderAction;
use App\Modules\Shared\Helpers\ActionTokenGenerator;
use App\Modules\Telegram\Keyboards\CategoryKeyboard;
use App\Modules\Telegram\Keyboards\ConfirmOrderKeyboard;
use App\Modules\Telegram\Keyboards\MainMenuKeyboard;
use App\Modules\Telegram\Keyboards\ServiceKeyboard;
use App\Modules\Telegram\Models\ConversationState;
use App\Modules\Wallet\Models\WalletAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

class PurchaseConversation
{
    public function __construct(
        private readonly CatalogQueryService $catalogQueryService,
        private readonly PriceCalculator $priceCalculator,
        private readonly PlaceOrderAction $placeOrderAction,
    ) {}

    /**
     * Handle a text message in the purchase conversation.
     */
    public function handleMessage(User $user, array $message, ConversationState $state): void
    {
        $chatId = (string) $message['chat']['id'];
        $text = trim($message['text'] ?? '');
        $step = $state->step;

        match ($step) {
            'awaiting_input' => $this->processInput($user, $chatId, $text, $state),
            'awaiting_quantity' => $this->processQuantity($user, $chatId, $text, $state),
            default => null,
        };
    }

    /**
     * Handle a callback query in the purchase conversation.
     */
    public function handleCallback(User $user, array $callbackQuery, ?ConversationState $state): void
    {
        $chatId = (string) ($callbackQuery['message']['chat']['id'] ?? $user->telegram_id);
        $data = $callbackQuery['data'] ?? '';

        if (str_starts_with($data, 'cat:')) {
            $categoryId = (int) substr($data, 4);
            $this->showServices($user, $chatId, $categoryId, $state);
        } elseif (str_starts_with($data, 'svc:')) {
            $serviceId = (int) substr($data, 4);
            $this->showServiceDetails($user, $chatId, $serviceId, $state);
        } elseif ($data === 'back:categories') {
            $this->showCategories($user, $chatId);
        } elseif (str_starts_with($data, 'confirm:')) {
            $actionToken = substr($data, 8);
            $this->confirmOrder($user, $chatId, $actionToken, $state);
        } elseif ($data === 'cancel:order') {
            $this->cancelOrder($user, $chatId);
        }
    }

    /**
     * Step 1: Show active categories.
     */
    public function showCategories(User $user, string $chatId): void
    {
        $categories = $this->catalogQueryService->getActiveCategories();

        if ($categories->isEmpty()) {
            SendTelegramMessageJob::dispatch($chatId, 'در حال حاضر سرویسی فعال نیست.');
            return;
        }

        $manager = app(ConversationManager::class);
        $manager->startConversation($user, 'purchase', 'selecting_category');

        $keyboard = CategoryKeyboard::build($categories);
        SendTelegramMessageJob::dispatch($chatId, '📂 دسته‌بندی مورد نظر خود را انتخاب کنید:', $keyboard);
    }

    /**
     * Step 2: Show services for selected category.
     */
    private function showServices(User $user, string $chatId, int $categoryId, ?ConversationState $state): void
    {
        $services = $this->catalogQueryService->getServicesByCategory($categoryId);

        if ($services->isEmpty()) {
            SendTelegramMessageJob::dispatch($chatId, 'در این دسته‌بندی سرویسی فعال نیست.');
            return;
        }

        if ($state) {
            $data = $state->data_json ?? [];
            $data['category_id'] = $categoryId;
            $state->update([
                'step' => 'selecting_service',
                'data_json' => $data,
                'expires_at' => now()->addMinutes(30),
            ]);
        }

        $keyboard = ServiceKeyboard::build($services);
        SendTelegramMessageJob::dispatch($chatId, '📋 سرویس مورد نظر خود را انتخاب کنید:', $keyboard);
    }

    /**
     * Step 3: Show service details and ask for inputs.
     */
    private function showServiceDetails(User $user, string $chatId, int $serviceId, ?ConversationState $state): void
    {
        try {
            $service = $this->catalogQueryService->getServiceById($serviceId);
        } catch (Throwable) {
            SendTelegramMessageJob::dispatch($chatId, 'سرویس انتخابی یافت نشد.');
            return;
        }

        if (!$service->is_active) {
            SendTelegramMessageJob::dispatch($chatId, 'این سرویس در حال حاضر غیرفعال است.');
            return;
        }

        $priceToman = intdiv($service->price_per_1000_rial, 10);
        $details = "📌 <b>{$service->title}</b>\n";
        if ($service->description) {
            $details .= "\n{$service->description}\n";
        }
        $details .= "\n💰 قیمت هر ۱۰۰۰ عدد: {$priceToman} تومان";
        $details .= "\n📊 حداقل: {$service->min_quantity} | حداکثر: {$service->max_quantity}";
        if ($service->estimated_time) {
            $details .= "\n⏱ زمان تخمینی: {$service->estimated_time}";
        }

        // Step 4: Ask for dynamic inputs
        $schema = $service->input_schema_json ?? [];
        $data = $state ? ($state->data_json ?? []) : [];
        $data['service_id'] = $serviceId;
        $data['inputs'] = [];
        $data['input_schema'] = $schema;
        $data['current_input_index'] = 0;

        if (!empty($schema)) {
            $firstField = $schema[0];
            $label = $firstField['label'] ?? $firstField['name'];
            $placeholder = isset($firstField['placeholder']) ? "\nمثال: {$firstField['placeholder']}" : '';
            $details .= "\n\n✏️ لطفاً {$label} را وارد کنید:{$placeholder}";

            if ($state) {
                $state->update([
                    'step' => 'awaiting_input',
                    'data_json' => $data,
                    'expires_at' => now()->addMinutes(30),
                ]);
            }
        }

        SendTelegramMessageJob::dispatch($chatId, $details);
    }

    /**
     * Step 4: Process dynamic input from user.
     */
    private function processInput(User $user, string $chatId, string $text, ConversationState $state): void
    {
        $data = $state->data_json ?? [];
        $schema = $data['input_schema'] ?? [];
        $currentIndex = $data['current_input_index'] ?? 0;

        if (!isset($schema[$currentIndex])) {
            $this->askQuantity($user, $chatId, $state, $data);
            return;
        }

        $field = $schema[$currentIndex];
        $name = $field['name'];
        $type = $field['type'] ?? 'text';
        $label = $field['label'] ?? $name;

        // Validate input
        if (trim($text) === '') {
            SendTelegramMessageJob::dispatch($chatId, "❌ فیلد «{$label}» نمی‌تواند خالی باشد. لطفاً دوباره وارد کنید:");
            return;
        }

        if ($type === 'url' && !filter_var($text, FILTER_VALIDATE_URL)) {
            SendTelegramMessageJob::dispatch($chatId, "❌ لطفاً یک لینک معتبر وارد کنید:");
            return;
        }

        $data['inputs'][$name] = $text;
        $data['current_input_index'] = $currentIndex + 1;

        // Check if there are more inputs
        if (isset($schema[$currentIndex + 1])) {
            $nextField = $schema[$currentIndex + 1];
            $nextLabel = $nextField['label'] ?? $nextField['name'];
            $placeholder = isset($nextField['placeholder']) ? "\nمثال: {$nextField['placeholder']}" : '';

            $state->update([
                'data_json' => $data,
                'expires_at' => now()->addMinutes(30),
            ]);

            SendTelegramMessageJob::dispatch($chatId, "✏️ لطفاً {$nextLabel} را وارد کنید:{$placeholder}");
            return;
        }

        // All inputs collected — ask quantity
        $this->askQuantity($user, $chatId, $state, $data);
    }

    /**
     * Step 5: Ask for quantity.
     */
    private function askQuantity(User $user, string $chatId, ConversationState $state, array $data): void
    {
        $service = $this->catalogQueryService->getServiceById($data['service_id']);
        $wallet = WalletAccount::where('user_id', $user->id)->first();
        $balance = $wallet ? $wallet->available_amount_rial : 0;
        $maxAffordable = $this->priceCalculator->maxAffordableQuantity(
            $balance,
            $service->price_per_1000_rial,
            $service->max_quantity,
        );

        $balanceToman = intdiv($balance, 10);
        $message = "📊 تعداد مورد نظر خود را وارد کنید:\n";
        $message .= "حداقل: {$service->min_quantity} | حداکثر: {$service->max_quantity}\n";
        $message .= "💰 موجودی شما: {$balanceToman} تومان\n";
        $message .= "📈 حداکثر قابل سفارش با موجودی فعلی: {$maxAffordable} عدد";

        $data['current_input_index'] = null;
        $state->update([
            'step' => 'awaiting_quantity',
            'data_json' => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        SendTelegramMessageJob::dispatch($chatId, $message);
    }

    /**
     * Step 5: Process quantity input.
     */
    private function processQuantity(User $user, string $chatId, string $text, ConversationState $state): void
    {
        if (!ctype_digit($text) || (int) $text < 1) {
            SendTelegramMessageJob::dispatch($chatId, '❌ لطفاً یک عدد صحیح مثبت وارد کنید:');
            return;
        }

        $quantity = (int) $text;
        $data = $state->data_json ?? [];
        $service = $this->catalogQueryService->getServiceById($data['service_id']);

        if ($quantity < $service->min_quantity) {
            SendTelegramMessageJob::dispatch($chatId, "❌ حداقل تعداد سفارش {$service->min_quantity} عدد است.");
            return;
        }
        if ($quantity > $service->max_quantity) {
            SendTelegramMessageJob::dispatch($chatId, "❌ حداکثر تعداد سفارش {$service->max_quantity} عدد است.");
            return;
        }

        // Check affordability
        $amountRial = $this->priceCalculator->calculate($quantity, $service->price_per_1000_rial);
        $wallet = WalletAccount::where('user_id', $user->id)->first();
        $balance = $wallet ? $wallet->available_amount_rial : 0;

        if ($amountRial > $balance) {
            $balanceToman = intdiv($balance, 10);
            SendTelegramMessageJob::dispatch($chatId, "❌ موجودی کیف پول شما ({$balanceToman} تومان) برای این سفارش کافی نیست.");
            return;
        }

        $data['quantity'] = $quantity;
        $data['amount_rial'] = $amountRial;

        // Step 6 & 7: Show summary with action token
        $this->showSummary($user, $chatId, $state, $data, $service);
    }

    /**
     * Step 6: Show order summary. Step 7: Generate action token.
     */
    private function showSummary(User $user, string $chatId, ConversationState $state, array $data, $service): void
    {
        $amountToman = intdiv($data['amount_rial'], 10);
        $inputs = $data['inputs'] ?? [];
        $inputDisplay = '';
        foreach ($inputs as $key => $value) {
            $inputDisplay .= "\n🔗 {$key}: {$value}";
        }

        $summary = "📋 <b>خلاصه سفارش</b>\n";
        $summary .= "\n🏷 سرویس: {$service->title}";
        $summary .= $inputDisplay;
        $summary .= "\n📊 تعداد: {$data['quantity']}";
        $summary .= "\n💰 مبلغ کل: {$amountToman} تومان";
        $summary .= "\n\nبرای ثبت نهایی سفارش روی «تأیید نهایی» کلیک کنید.";

        // Generate action token
        $actionToken = ActionTokenGenerator::generate();
        $data['action_token'] = $actionToken;

        $state->update([
            'step' => 'awaiting_confirmation',
            'data_json' => $data,
            'expires_at' => now()->addMinutes(5),
        ]);

        $keyboard = ConfirmOrderKeyboard::build($actionToken);
        SendTelegramMessageJob::dispatch($chatId, $summary, $keyboard);
    }

    /**
     * Step 8: Process order confirmation.
     */
    private function confirmOrder(User $user, string $chatId, string $actionToken, ?ConversationState $state): void
    {
        if (!$state) {
            SendTelegramMessageJob::dispatch($chatId, '❌ نشست شما منقضی شده است. لطفاً دوباره از «خرید سرویس» اقدام کنید.', MainMenuKeyboard::get());
            return;
        }

        $data = $state->data_json ?? [];
        $storedToken = $data['action_token'] ?? '';

        // Validate token matches
        if (!hash_equals($storedToken, $actionToken)) {
            SendTelegramMessageJob::dispatch($chatId, '❌ توکن تأیید نامعتبر است.');
            return;
        }

        // Check if conversation has expired
        if ($state->isExpired()) {
            $state->delete();
            SendTelegramMessageJob::dispatch($chatId, '❌ زمان تأیید سفارش منقضی شده است. لطفاً دوباره اقدام کنید.', MainMenuKeyboard::get());
            return;
        }

        try {
            $order = $this->placeOrderAction->execute(
                user: $user,
                actionToken: $actionToken,
                serviceId: $data['service_id'],
                inputs: $data['inputs'] ?? [],
                quantity: $data['quantity'],
            );

            // Clear conversation state after successful order
            $state->delete();

            $amountToman = $order->amountToman();
            $message = "✅ سفارش شما با موفقیت ثبت شد!\n";
            $message .= "\n📝 کد سفارش: {$order->order_code}";
            $message .= "\n📊 تعداد: {$order->quantity}";
            $message .= "\n💰 مبلغ: {$amountToman} تومان";
            $message .= "\n📌 وضعیت: در صف ارسال";
            $message .= "\n\nبرای پیگیری سفارش از منوی «پیگیری سفارش» استفاده کنید.";

            SendTelegramMessageJob::dispatch($chatId, $message, MainMenuKeyboard::get());
        } catch (Throwable $e) {
            Log::error('PurchaseConversation: Order placement failed.', ['error' => $e->getMessage(), 'user_id' => $user->id]);

            // Check if this was a duplicate confirmation (order already exists)
            $existingOrder = \App\Modules\Orders\Models\Order::where('action_token', $actionToken)->first();
            if ($existingOrder) {
                $state->delete();
                SendTelegramMessageJob::dispatch($chatId, 'ℹ️ این سفارش قبلاً ثبت شده است.', MainMenuKeyboard::get());
                return;
            }

            $errorMsg = $e->getMessage() ?: 'خطای داخلی رخ داده است.';
            SendTelegramMessageJob::dispatch($chatId, "❌ {$errorMsg}", MainMenuKeyboard::get());
        }
    }

    /**
     * Cancel the current order and clear conversation.
     */
    private function cancelOrder(User $user, string $chatId): void
    {
        $manager = app(ConversationManager::class);
        $manager->clearConversation($user);

        SendTelegramMessageJob::dispatch($chatId, '🚫 سفارش لغو شد.', MainMenuKeyboard::get());
    }
}
