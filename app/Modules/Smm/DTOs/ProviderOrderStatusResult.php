<?php

declare(strict_types=1);

namespace App\Modules\Smm\DTOs;

use App\Modules\Orders\Enums\OrderStatus;

/**
 * Immutable Data Transfer Object representing the result of querying an order's
 * status from an SMM provider.
 *
 * The adapter is responsible for mapping the provider's raw status string to
 * the internal OrderStatus enum before constructing this DTO.
 */
final readonly class ProviderOrderStatusResult
{
    public function __construct(
        public OrderStatus $status,
        public ?int $remains = null,
    ) {}
}
