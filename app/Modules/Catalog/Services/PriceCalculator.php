<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

class PriceCalculator
{
    public function calculate(int $quantity, int $pricePer1000Rial): int
    {
        return (int) ceil(($quantity * $pricePer1000Rial) / 1000);
    }

    public function maxAffordableQuantity(int $balanceRial, int $pricePer1000Rial, int $maxQuantity): int
    {
        if ($pricePer1000Rial <= 0) {
            return $maxQuantity;
        }
        
        $affordableQuantity = (int) floor(($balanceRial * 1000) / $pricePer1000Rial);
        
        return min($affordableQuantity, $maxQuantity);
    }
}
