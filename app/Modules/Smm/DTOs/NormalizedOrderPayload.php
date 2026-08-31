<?php

declare(strict_types=1);

namespace App\Modules\Smm\DTOs;

/**
 * Provider-agnostic DTO that normalizes order data for any SMM adapter.
 */
final readonly class NormalizedOrderPayload
{
    /**
     * @param string $serviceId  The provider's service identifier.
     * @param string $link       Target URL for the service.
     * @param int    $quantity   Number of units to order.
     * @param array<string, mixed> $extraInputs  Additional dynamic inputs.
     */
    public function __construct(
        public string $serviceId,
        public string $link,
        public int $quantity,
        public array $extraInputs = [],
    ) {}

    /**
     * Convert to the array format expected by SmmProviderContract::createOrder().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'service' => $this->serviceId,
            'link' => $this->link,
            'quantity' => $this->quantity,
        ], $this->extraInputs);
    }
}
