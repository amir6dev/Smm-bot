<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Jobs\SubmitSmmOrderJob;
use App\Models\User;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\PriceCalculator;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Shared\Helpers\OrderCodeGenerator;
use App\Modules\Wallet\Actions\DebitWalletAction;
use App\Modules\Wallet\Models\WalletAccount;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class PlaceOrderAction
{
    public function __construct(
        private readonly DebitWalletAction $debitWalletAction,
        private readonly PriceCalculator $priceCalculator,
    ) {}

    /**
     * Execute the complete order placement flow inside a single DB transaction.
     *
     * @param User $user The authenticated user placing the order.
     * @param string $actionToken The one-time confirmation token.
     * @param int $serviceId The selected service ID.
     * @param array<string, mixed> $inputs User-provided dynamic inputs (link, etc.).
     * @param int $quantity The requested quantity.
     *
     * @return Order The created order.
     *
     * @throws InvalidArgumentException If validation fails.
     * @throws RuntimeException If the token is invalid/expired/used.
     */
    public function execute(
        User $user,
        string $actionToken,
        int $serviceId,
        array $inputs,
        int $quantity,
    ): Order {
        // Step 1: Validate Action Token — check for already-used token first (idempotency)
        $existingOrder = Order::where('action_token', $actionToken)
            ->where('user_id', $user->id)
            ->first();

        if ($existingOrder) {
            // Token was already used — return the existing order (idempotent)
            return $existingOrder;
        }

        // Step 2–11: Run everything in a single DB transaction
        $order = DB::transaction(function () use ($user, $actionToken, $serviceId, $inputs, $quantity) {
            // Step 3: Load and validate service + provider
            $service = Service::with('provider')
                ->availableForPurchase()
                ->find($serviceId);

            if (!$service) {
                throw new InvalidArgumentException('سرویس انتخابی نامعتبر یا غیرفعال است.');
            }

            if (!$service->provider || !$service->provider->is_active) {
                throw new InvalidArgumentException('ارائه‌دهنده سرویس غیرفعال است.');
            }

            // Step 4: Validate dynamic inputs against service schema
            $this->validateInputs($inputs, $service->input_schema_json ?? []);

            // Step 5: Validate quantity
            if ($quantity < 1 || !is_int($quantity)) {
                throw new InvalidArgumentException('تعداد باید عدد صحیح مثبت باشد.');
            }
            if ($quantity < $service->min_quantity) {
                throw new InvalidArgumentException("حداقل تعداد سفارش {$service->min_quantity} عدد است.");
            }
            if ($quantity > $service->max_quantity) {
                throw new InvalidArgumentException("حداکثر تعداد سفارش {$service->max_quantity} عدد است.");
            }

            // Step 6: Calculate price (integer arithmetic only)
            $amountRial = $this->priceCalculator->calculate($quantity, $service->price_per_1000_rial);

            // Step 8: Create order first (to get order ID for idempotency key)
            $orderCode = OrderCodeGenerator::generate();
            $order = Order::create([
                'user_id' => $user->id,
                'service_id' => $service->id,
                'order_code' => $orderCode,
                'status' => OrderStatus::QUEUED->value,
                'quantity' => $quantity,
                'amount_rial' => $amountRial,
                'target_data_json' => $inputs,
                'snapshot_json' => [
                    'service_title' => $service->title,
                    'category_name' => $service->category?->name,
                    'price_per_1000_rial' => $service->price_per_1000_rial,
                    'min_quantity' => $service->min_quantity,
                    'max_quantity' => $service->max_quantity,
                    'provider_id' => $service->provider_id,
                    'provider_code' => $service->provider?->code,
                    'provider_service_id' => $service->provider_service_id,
                ],
                'action_token' => $actionToken,
                'action_token_expires_at' => now()->addMinutes(5),
            ]);

            // Step 7: Debit wallet with order-based idempotency key
            $wallet = WalletAccount::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $idempotencyKey = "order-debit:{$order->id}";

            $this->debitWalletAction->execute(
                account: $wallet,
                amountRial: $amountRial,
                reason: "پرداخت سفارش {$orderCode}",
                referenceType: 'order',
                referenceId: $order->id,
                idempotencyKey: $idempotencyKey,
                actorType: 'user',
                actorId: $user->id,
            );

            // Step 9: Create initial OrderEvent (draft → queued)
            OrderEvent::create([
                'order_id' => $order->id,
                'status_from' => OrderStatus::DRAFT->value,
                'status_to' => OrderStatus::QUEUED->value,
                'source' => 'user',
                'payload_json' => [
                    'action' => 'order_placed',
                    'quantity' => $quantity,
                    'amount_rial' => $amountRial,
                ],
                'actor_id' => $user->id,
            ]);

            // Step 10: Token is already stored on the order — uniqueness constraint prevents reuse

            return $order;
        });

        // Step 12: Dispatch job AFTER COMMIT (uses afterCommit by default in Laravel)
        SubmitSmmOrderJob::dispatch($order->id);

        return $order;
    }

    /**
     * Validate user inputs against the service's input_schema_json.
     *
     * @param array<string, mixed> $inputs
     * @param array<int, array<string, mixed>> $schema
     */
    private function validateInputs(array $inputs, array $schema): void
    {
        foreach ($schema as $field) {
            $name = $field['name'] ?? null;
            if (!$name) {
                continue;
            }

            $required = $field['required'] ?? false;
            $value = $inputs[$name] ?? null;

            if ($required && (is_null($value) || (is_string($value) && trim($value) === ''))) {
                $label = $field['label'] ?? $name;
                throw new InvalidArgumentException("فیلد «{$label}» الزامی است.");
            }

            if ($value !== null && ($field['type'] ?? '') === 'url') {
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    $label = $field['label'] ?? $name;
                    throw new InvalidArgumentException("فیلد «{$label}» باید یک لینک معتبر باشد.");
                }
            }
        }
    }
}
