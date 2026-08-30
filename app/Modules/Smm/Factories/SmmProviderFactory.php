<?php

declare(strict_types=1);

namespace App\Modules\Smm\Factories;

use App\Modules\Smm\Adapters\MockProviderAdapter;
use App\Modules\Smm\Contracts\SmmProviderContract;
use InvalidArgumentException;

/**
 * Factory responsible for resolving the correct SMM provider adapter
 * based on a provider code string.
 *
 * Phase 1: All codes resolve to MockProviderAdapter.
 * Phase 3: Real adapters (V1, V2) will be added as additional match arms.
 *
 * Usage:
 *   $provider = SmmProviderFactory::make('mock');
 *   $result   = $provider->createOrder([...]);
 */
final class SmmProviderFactory
{
    /**
     * Resolve and return the appropriate SmmProviderContract implementation.
     *
     * @param string $providerCode The identifier for the desired provider.
     *
     * @throws InvalidArgumentException If the provider code is not recognized.
     *
     * @return SmmProviderContract
     */
    public static function make(string $providerCode): SmmProviderContract
    {
        return match ($providerCode) {
            // Phase 3: Add real provider adapters here, e.g.:
            // 'provider_v1' => new SmmV1Adapter(config('services.smm_v1')),
            // 'provider_v2' => new SmmV2Adapter(config('services.smm_v2')),

            default => new MockProviderAdapter(),
        };
    }
}
