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
}
