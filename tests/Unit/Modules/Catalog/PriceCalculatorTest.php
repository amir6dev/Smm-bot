<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Catalog;

use PHPUnit\Framework\TestCase;
use App\Modules\Catalog\Services\PriceCalculator;

class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PriceCalculator();
    }

    public function test_calculate_returns_correct_integer_amount(): void
    {
        $amount = $this->calculator->calculate(1500, 10000);
        
        $this->assertIsInt($amount);
        $this->assertSame(15000, $amount);
    }

    public function test_calculate_rounds_up_correctly_and_returns_integer(): void
    {
        $amount = $this->calculator->calculate(1505, 10500);
        
        $this->assertIsInt($amount);
        $this->assertSame(15803, $amount);
    }

    public function test_max_affordable_quantity_returns_correct_integer(): void
    {
        $quantity = $this->calculator->maxAffordableQuantity(15000, 10000, 5000);
        
        $this->assertIsInt($quantity);
        $this->assertSame(1500, $quantity);
    }

    public function test_max_affordable_quantity_respects_max_quantity_limit(): void
    {
        $quantity = $this->calculator->maxAffordableQuantity(100000, 10000, 5000);
        
        $this->assertIsInt($quantity);
        $this->assertSame(5000, $quantity);
    }

    public function test_max_affordable_quantity_rounds_down_correctly(): void
    {
        $quantity = $this->calculator->maxAffordableQuantity(15500, 10000, 5000);
        
        $this->assertIsInt($quantity);
        $this->assertSame(1550, $quantity);
    }

    // ──────────────────────────────────────────────
    // Step 6 — Extended boundary tests
    // ──────────────────────────────────────────────

    public function test_calculate_quantity_1(): void
    {
        // 1 * 50000 / 1000 = 50
        $amount = $this->calculator->calculate(1, 50000);

        $this->assertIsInt($amount);
        $this->assertSame(50, $amount);
    }

    public function test_calculate_quantity_1000(): void
    {
        // 1000 * 50000 / 1000 = 50000
        $amount = $this->calculator->calculate(1000, 50000);

        $this->assertIsInt($amount);
        $this->assertSame(50000, $amount);
    }

    public function test_calculate_quantity_1_000_000(): void
    {
        // 1_000_000 * 50000 / 1000 = 50_000_000
        $amount = $this->calculator->calculate(1_000_000, 50000);

        $this->assertIsInt($amount);
        $this->assertSame(50_000_000, $amount);
    }

    public function test_calculate_price_zero(): void
    {
        $amount = $this->calculator->calculate(500, 0);

        $this->assertIsInt($amount);
        $this->assertSame(0, $amount);
    }

    public function test_calculate_zero_quantity(): void
    {
        $amount = $this->calculator->calculate(0, 50000);

        $this->assertIsInt($amount);
        $this->assertSame(0, $amount);
    }

    public function test_calculate_spec_example(): void
    {
        // Spec example: quantity=500, price_per_1000=50000 Rial → result=25000 Rial
        $amount = $this->calculator->calculate(500, 50000);

        $this->assertIsInt($amount);
        $this->assertSame(25000, $amount);
    }

    public function test_calculate_never_returns_float(): void
    {
        // Use values that would produce non-integer in float arithmetic
        // 333 * 10000 / 1000 = 3330.0 — should be int 3330
        $amount = $this->calculator->calculate(333, 10000);

        $this->assertIsInt($amount);
        $this->assertSame(3330, $amount);
    }

    public function test_calculate_rounds_up_fractional_rial(): void
    {
        // 1 * 1 / 1000 = 0.001 → ceil → 1
        $amount = $this->calculator->calculate(1, 1);

        $this->assertIsInt($amount);
        $this->assertSame(1, $amount);
    }

    public function test_max_affordable_quantity_with_zero_price(): void
    {
        // If price is 0, everything is free — return max_quantity
        $quantity = $this->calculator->maxAffordableQuantity(10000, 0, 5000);

        $this->assertSame(5000, $quantity);
    }
}

