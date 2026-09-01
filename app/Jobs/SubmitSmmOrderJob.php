<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Orders\Actions\RefundOrderAction;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Smm\DTOs\NormalizedOrderPayload;
use App\Modules\Smm\Factories\SmmProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubmitSmmOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry up to 3 times with exponential backoff (10s, then 60s).
     */
    public int $tries = 3;

    /**
     * Backoff intervals in seconds between retry attempts.
     *
     * @var array<int>
     */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly int $orderId,
    ) {
        $this->queue = 'default';
    }

    public function handle(RefundOrderAction $refundOrderAction): void
    {
        // Step 1: Load order
        $order = Order::find($this->orderId);

        if (!$order) {
            Log::warning("SubmitSmmOrderJob: Order {$this->orderId} not found. Exiting.");
            return;
        }

        // Step 2: Ensure order status is queued (idempotency)
        if ($order->status === OrderStatus::SUBMITTED || $order->status === OrderStatus::PROCESSING || $order->status === OrderStatus::COMPLETED) {
            Log::info("SubmitSmmOrderJob: Order {$this->orderId} already submitted. Skipping.");
            return;
        }

        if ($order->status !== OrderStatus::QUEUED) {
            Log::warning("SubmitSmmOrderJob: Order {$this->orderId} has unexpected status '{$order->status->value}'. Exiting.");
            return;
        }

        // Step 3: Transition queued → submitting
        try {
            $order->transitionTo(OrderStatus::SUBMITTING, 'system', ['action' => 'smm_submission_started']);
        } catch (Throwable $e) {
            Log::error("SubmitSmmOrderJob: Failed to transition order {$this->orderId} to submitting.", ['error' => $e->getMessage()]);
            return;
        }

        // Step 4: Resolve provider via factory
        $snapshot = $order->snapshot_json ?? [];
        $providerCode = $snapshot['provider_code'] ?? 'mock';
        $provider = SmmProviderFactory::make($providerCode);

        // Step 5: Build normalized payload
        $inputs = $order->target_data_json ?? [];
        $payload = new NormalizedOrderPayload(
            serviceId: $snapshot['provider_service_id'] ?? '',
            link: $inputs['link'] ?? '',
            quantity: $order->quantity,
            extraInputs: collect($inputs)->except('link')->toArray(),
        );

        // Step 6: Call provider
        try {
            $result = $provider->createOrder($payload->toArray());
        } catch (Throwable $e) {
            // Retriable failure — revert to queued and let Laravel retry
            Log::error("SubmitSmmOrderJob: Provider threw exception for order {$this->orderId}.", ['error' => $e->getMessage()]);

            try {
                $order->transitionTo(OrderStatus::QUEUED, 'system', [
                    'action' => 'smm_submission_retry',
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                ]);
            } catch (Throwable $transitionError) {
                Log::error("SubmitSmmOrderJob: Could not revert order {$this->orderId} to queued.", ['error' => $transitionError->getMessage()]);
            }

            // Re-throw so Laravel's queue retry mechanism handles backoff
            throw $e;
        }

        // Step 7: Handle success
        if ($result->isSuccess) {
            $order->provider_order_id = $result->providerOrderId;
            $order->save();

            $order->transitionTo(OrderStatus::SUBMITTED, 'system', [
                'action' => 'smm_submission_success',
                'provider_order_id' => $result->providerOrderId,
            ]);

            // Notify user
            $this->notifyUser($order, 'success');

            return;
        }

        // Step 8: Handle explicit provider failure (non-retriable — provider returned failure result)
        $this->handleTerminalFailure($order, $refundOrderAction, $result->errorMessage ?? 'Unknown error');
    }

    /**
     * Called by Laravel when all retry attempts are exhausted.
     * Handles final failure: transition to failed, refund, notify.
     */
    public function failed(Throwable $exception): void
    {
        $order = Order::find($this->orderId);
        if (!$order) {
            return;
        }

        // Only handle if order is still in a retriable state
        if (!in_array($order->status, [OrderStatus::QUEUED, OrderStatus::SUBMITTING], true)) {
            return;
        }

        $refundOrderAction = app(RefundOrderAction::class);
        $this->handleTerminalFailure($order, $refundOrderAction, $exception->getMessage());
    }

    /**
     * Handle terminal SMM submission failure: transition to failed, refund, notify.
     */
    private function handleTerminalFailure(Order $order, RefundOrderAction $refundOrderAction, string $errorMessage): void
    {
        Log::warning("SubmitSmmOrderJob: Order {$order->id} submission failed permanently.", ['error' => $errorMessage]);

        try {
            $order->transitionTo(OrderStatus::FAILED, 'system', [
                'action' => 'smm_submission_failed',
                'error' => $errorMessage,
            ]);
        } catch (Throwable $e) {
            Log::error("SubmitSmmOrderJob: Could not transition order {$order->id} to failed.", ['error' => $e->getMessage()]);
        }

        try {
            $refundOrderAction->execute($order, 'بازگشت وجه به دلیل خطا در ارسال سفارش به ارائه‌دهنده');
        } catch (Throwable $e) {
            Log::error("SubmitSmmOrderJob: Refund failed for order {$order->id}.", ['error' => $e->getMessage()]);
        }

        $this->notifyUser($order, 'failure');
    }

    /**
     * Send Telegram notification to the user about order status.
     */
    private function notifyUser(Order $order, string $type): void
    {
        $user = $order->user;
        if (!$user) {
            return;
        }

        $chatId = (string) $user->telegram_id;

        if ($type === 'success') {
            $message = "✅ سفارش شما با موفقیت ثبت شد.\nکد سفارش: {$order->order_code}";
        } else {
            $amountToman = $order->amountToman();
            $message = "❌ ثبت سفارش ناموفق بود.\nمبلغ {$amountToman} تومان به کیف پول شما بازگردانده شد.";
        }

        SendTelegramMessageJob::dispatch($chatId, $message);
    }
}

