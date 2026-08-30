<?php

declare(strict_types=1);

namespace App\Modules\Smm\DTOs;

/**
 * Immutable Data Transfer Object representing the result of placing an order
 * with an SMM provider.
 *
 * All provider adapters MUST return this DTO from their createOrder() method.
 * This enforces a strict, predictable contract across all provider implementations.
 */
final readonly class ProviderOrderResult
{
    public function __construct(
        public bool $isSuccess,
        public ?string $providerOrderId = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * Named constructor for a successful order placement.
     */
    public static function success(string $providerOrderId): self
    {
        return new self(
            isSuccess: true,
            providerOrderId: $providerOrderId,
            errorMessage: null,
        );
    }

    /**
     * Named constructor for a failed order placement.
     */
    public static function failure(string $errorMessage): self
    {
        return new self(
            isSuccess: false,
            providerOrderId: null,
            errorMessage: $errorMessage,
        );
    }
}
