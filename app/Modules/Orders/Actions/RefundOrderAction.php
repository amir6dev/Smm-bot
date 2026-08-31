<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Wallet\Actions\CreditWalletAction;
use App\Modules\Wallet\Models\WalletAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RefundOrderAction
{
    public function __construct(
        private readonly CreditWalletAction $creditWalletAction,
    ) {}

    /**
     * Refund the order amount to the user's wallet.
     *
     * @param Order $order The order to refund.
     * @param string $reason Human-readable refund reason.
     *
     * @return Order The updated order.
     *
     * @throws RuntimeException If the order cannot be refunded or was already refunded.
     */
    public function execute(Order $order, string $reason = 'بازگشت وجه سفارش ناموفق'): Order
    {
        // Prevent duplicate refunds — idempotency check
        $idempotencyKey = "order-refund:{$order->id}";

        return DB::transaction(function () use ($order, $reason, $idempotencyKey) {
            // Re-load to get fresh status inside transaction
            $order = Order::lockForUpdate()->findOrFail($order->id);

            // Only refund from allowed states
            if (!in_array($order->status, [OrderStatus::FAILED, OrderStatus::PARTIAL], true)) {
                // Check if already refunded — idempotent return
                if ($order->status === OrderStatus::REFUNDED) {
                    return $order;
                }
                throw new RuntimeException("سفارش در وضعیت قابل بازگشت نیست.");
            }

            $wallet = WalletAccount::where('user_id', $order->user_id)->lockForUpdate()->firstOrFail();

            $this->creditWalletAction->execute(
                account: $wallet,
                amountRial: $order->amount_rial,
                reason: $reason,
                referenceType: 'order',
                referenceId: $order->id,
                idempotencyKey: $idempotencyKey,
                actorType: 'system',
                actorId: null,
            );

            $previousStatus = $order->status;
            $order->status = OrderStatus::REFUNDED;
            $order->save();

            OrderEvent::create([
                'order_id' => $order->id,
                'status_from' => $previousStatus->value,
                'status_to' => OrderStatus::REFUNDED->value,
                'source' => 'system',
                'payload_json' => [
                    'action' => 'refund',
                    'reason' => $reason,
                    'amount_rial' => $order->amount_rial,
                ],
            ]);

            return $order;
        });
    }
}
