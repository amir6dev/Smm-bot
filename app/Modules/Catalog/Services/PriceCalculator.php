<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

class PriceCalculator
{
    /**
     * Calculate the total price in Rial using integer-only arithmetic.
     *
     * Formula: ceil(quantity × price_per_1000_rial / 1000)
     * Implemented as: intdiv(quantity * price + 999, 1000) to avoid float division.
     */
    public function calculate(int $quantity, int $pricePer1000Rial): int
    {
        $numerator = $quantity * $pricePer1000Rial;

        if ($numerator <= 0) {
            return 0;
        }

        // Integer ceiling division: intdiv(n + d - 1, d)
        return intdiv($numerator + 999, 1000);
    }

    /**
     * Calculate the maximum quantity a user can afford using integer-only arithmetic.
     *
     * Uses integer floor division (intdiv) — no float intermediary.
     */
    public function maxAffordableQuantity(int $balanceRial, int $pricePer1000Rial, int $maxQuantity): int
    {
        if ($pricePer1000Rial <= 0) {
            return $maxQuantity;
        }

        // Integer floor division: intdiv(balance * 1000, price)
        $affordableQuantity = intdiv($balanceRial * 1000, $pricePer1000Rial);

        return min($affordableQuantity, $maxQuantity);
    }
}
}
