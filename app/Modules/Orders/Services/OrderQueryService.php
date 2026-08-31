<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrderQueryService
{
    /**
     * Get paginated orders for a specific user.
     */
    public function getOrdersForUser(int $userId, int $perPage = 5): LengthAwarePaginator
    {
        return Order::forUser($userId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Search for an order by code, enforcing user ownership.
     */
    public function findByCodeForUser(string $orderCode, int $userId): ?Order
    {
        return Order::forUser($userId)
            ->where('order_code', $orderCode)
            ->first();
    }

    /**
     * Get order detail by ID, enforcing user ownership.
     */
    public function findByIdForUser(int $orderId, int $userId): ?Order
    {
        return Order::forUser($userId)->find($orderId);
    }
}
