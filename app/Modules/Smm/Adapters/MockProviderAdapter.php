<?php

declare(strict_types=1);

namespace App\Modules\Smm\Adapters;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Smm\Contracts\SmmProviderContract;
use App\Modules\Smm\DTOs\ProviderOrderResult;
use App\Modules\Smm\DTOs\ProviderOrderStatusResult;

/**
 * Mock SMM provider adapter for development and testing.
 *
 * This adapter simulates SMM provider behavior without making any real HTTP calls.
 * It returns predictable, fake data that exercises the full order flow pipeline.
 *
 * NOT for production use. Will be replaced by real V1/V2 adapters in Phase 3.
 */
final class MockProviderAdapter implements SmmProviderContract
{
    private const MOCK_BALANCE = 100000;

    /**
     * Simulate placing an order. Returns success with a MOCK-prefixed order ID.
     *
     * @param array<string, mixed> $normalizedPayload
     */
    public function createOrder(array $normalizedPayload): ProviderOrderResult
    {
        // Simulate network latency (100ms to keep tests fast)
        usleep(100_000);

        $mockOrderId = 'MOCK-' . random_int(10000, 99999);

        return ProviderOrderResult::success($mockOrderId);
    }

    /**
     * Simulate checking order status. Randomly returns PROCESSING or COMPLETED.
     */
    public function getOrderStatus(string $providerOrderId): ProviderOrderStatusResult
    {
        // Simulate network latency
        usleep(100_000);

        // 50/50 chance of processing vs completed
        $isCompleted = random_int(0, 1) === 1;

        if ($isCompleted) {
            return new ProviderOrderStatusResult(
                status: OrderStatus::COMPLETED,
                remains: 0,
            );
        }

        return new ProviderOrderStatusResult(
            status: OrderStatus::PROCESSING,
            remains: random_int(100, 500),
        );
    }

    /**
     * Return a fixed mock balance.
     */
    public function getBalance(): float|int
    {
        return self::MOCK_BALANCE;
    }
}
