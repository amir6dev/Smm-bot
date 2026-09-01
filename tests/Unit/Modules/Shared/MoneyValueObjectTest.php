<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shared;

use App\Modules\Shared\ValueObjects\Money;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

class MoneyValueObjectTest extends TestCase
{
    // ──────────────────────────────────────────────
    // fromToman / toRial
    // ──────────────────────────────────────────────

    public function test_from_toman_converts_to_rial_correctly(): void
    {
        $money = Money::fromToman(100);

        $this->assertSame(1000, $money->toRial());
    }

    public function test_from_toman_100_to_rial_equals_1000(): void
    {
        $this->assertSame(1000, Money::fromToman(100)->toRial());
    }

    public function test_from_toman_zero(): void
    {
        $money = Money::fromToman(0);

        $this->assertSame(0, $money->toRial());
        $this->assertSame(0, $money->toToman());
    }

    public function test_from_toman_one(): void
    {
        $money = Money::fromToman(1);

        $this->assertSame(10, $money->toRial());
    }

    public function test_from_toman_large_value(): void
    {
        $money = Money::fromToman(1_000_000_000);

        $this->assertSame(10_000_000_000, $money->toRial());
        $this->assertSame(1_000_000_000, $money->toToman());
    }

    public function test_from_toman_overflow_throws_exception(): void
    {
        $this->expectException(OverflowException::class);

        // PHP_INT_MAX / 10 + 1 would overflow
        Money::fromToman(intdiv(PHP_INT_MAX, 10) + 1);
    }

    // ──────────────────────────────────────────────
    // fromRial
    // ──────────────────────────────────────────────

    public function test_from_rial_preserves_value(): void
    {
        $money = Money::fromRial(12345);

        $this->assertSame(12345, $money->toRial());
    }

    // ──────────────────────────────────────────────
    // toToman — integer arithmetic
    // ──────────────────────────────────────────────

    public function test_to_toman_uses_integer_division(): void
    {
        // 15 Rial / 10 = 1 Toman (integer division, not 1.5)
        $money = Money::fromRial(15);

        $this->assertSame(1, $money->toToman());
        $this->assertIsInt($money->toToman());
    }

    public function test_to_toman_rounds_down(): void
    {
        // 19 Rial / 10 = 1 Toman (truncated, not 2)
        $money = Money::fromRial(19);

        $this->assertSame(1, $money->toToman());
    }

    public function test_to_toman_exact_conversion(): void
    {
        $money = Money::fromRial(500);

        $this->assertSame(50, $money->toToman());
    }

    public function test_to_toman_returns_integer_type(): void
    {
        $result = Money::fromToman(100)->toToman();

        $this->assertIsInt($result);
        $this->assertSame(100, $result);
    }

    // ──────────────────────────────────────────────
    // add / subtract
    // ──────────────────────────────────────────────

    public function test_add_produces_correct_result(): void
    {
        $a = Money::fromToman(100); // 1000 Rial
        $b = Money::fromToman(50);  // 500 Rial

        $result = $a->add($b);

        $this->assertSame(1500, $result->toRial());
    }

    public function test_subtract_produces_correct_result(): void
    {
        $a = Money::fromToman(100); // 1000 Rial
        $b = Money::fromToman(30);  // 300 Rial

        $result = $a->subtract($b);

        $this->assertSame(700, $result->toRial());
    }

    public function test_subtract_to_zero(): void
    {
        $a = Money::fromToman(100);
        $b = Money::fromToman(100);

        $result = $a->subtract($b);

        $this->assertSame(0, $result->toRial());
    }

    public function test_subtract_negative_result_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $a = Money::fromToman(50);
        $b = Money::fromToman(100);

        $a->subtract($b);
    }

    // ──────────────────────────────────────────────
    // Comparison
    // ──────────────────────────────────────────────

    public function test_is_less_than(): void
    {
        $a = Money::fromToman(50);
        $b = Money::fromToman(100);

        $this->assertTrue($a->isLessThan($b));
        $this->assertFalse($b->isLessThan($a));
    }

    public function test_is_greater_than_or_equal(): void
    {
        $a = Money::fromToman(100);
        $b = Money::fromToman(100);
        $c = Money::fromToman(50);

        $this->assertTrue($a->isGreaterThanOrEqual($b));
        $this->assertTrue($a->isGreaterThanOrEqual($c));
        $this->assertFalse($c->isGreaterThanOrEqual($a));
    }

    // ──────────────────────────────────────────────
    // Immutability & type safety
    // ──────────────────────────────────────────────

    public function test_money_is_immutable(): void
    {
        $original = Money::fromToman(100);
        $added = $original->add(Money::fromToman(50));

        // Original should not be mutated
        $this->assertSame(1000, $original->toRial());
        $this->assertSame(1500, $added->toRial());
    }

    public function test_all_arithmetic_returns_integer(): void
    {
        $money = Money::fromToman(333);

        $this->assertIsInt($money->toRial());
        $this->assertIsInt($money->toToman());
        $this->assertIsInt($money->add(Money::fromToman(1))->toRial());
        $this->assertIsInt($money->subtract(Money::fromToman(1))->toRial());
    }
}
