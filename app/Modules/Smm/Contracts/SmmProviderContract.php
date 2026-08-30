<?php

declare(strict_types=1);

namespace App\Modules\Smm\Contracts;

use App\Modules\Smm\DTOs\ProviderOrderResult;
use App\Modules\Smm\DTOs\ProviderOrderStatusResult;

/**
 * Contract that every SMM provider adapter MUST implement.
 *
 * This interface guarantees a uniform API surface across all providers
 * (Mock, V1, V2, etc.), enabling seamless swapping at the factory level.
 *
 * Adapters are responsible for:
 *  - Translating the normalized payload into provider-specific API calls.
 *  - Mapping provider responses into strictly typed DTOs.
 *  - Handling provider-specific errors and surfacing them via the DTOs.
 */
interface SmmProviderContract
{
    /**
     * Place an order with the SMM provider.
     *
     * @param array<string, mixed> $normalizedPayload Provider-agnostic order data.
     *   Expected keys (vary by service type):
     *     - 'service_id' (int|string)  – The provider's service identifier.
     *     - 'link'       (string)      – Target URL for the service.
     *     - 'quantity'   (int)         – Number of units to order.
     *
     * @return ProviderOrderResult Strictly typed result with success/failure info.
     */
    public function createOrder(array $normalizedPayload): ProviderOrderResult;

    /**
     * Query the current status of a previously placed order.
     *
     * @param string $providerOrderId The order ID returned by the provider.
     *
     * @return ProviderOrderStatusResult Strictly typed result with mapped status.
     */
    public function getOrderStatus(string $providerOrderId): ProviderOrderStatusResult;

    /**
     * Retrieve the current account balance from the provider.
     *
     * @return float|int The balance amount in the provider's currency.
     */
    public function getBalance(): float|int;
}
