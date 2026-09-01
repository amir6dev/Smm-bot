<?php

declare(strict_types=1);

namespace App\Modules\Shared\ValueObjects;

use InvalidArgumentException;
use OverflowException;

final readonly class Money
{
    public function __construct(
        public int $amountRial
    ) {
    }

    public static function fromToman(int $toman): self
    {
        // Overflow protection: PHP_INT_MAX / 10 = max safe toman value
        if ($toman > 0 && $toman > intdiv(PHP_INT_MAX, 10)) {
            throw new OverflowException("Toman amount {$toman} would overflow when converted to Rial.");
        }

        return new self($toman * 10);
    }

    public static function fromRial(int $rial): self
    {
        return new self($rial);
    }

    public function toToman(): int
    {
        return intdiv($this->amountRial, 10);
    }

    public function toRial(): int
    {
        return $this->amountRial;
    }

    public function add(self $other): self
    {
        return new self($this->amountRial + $other->amountRial);
    }

    public function subtract(self $other): self
    {
        $newAmount = $this->amountRial - $other->amountRial;
        if ($newAmount < 0) {
            throw new InvalidArgumentException("Resulting money amount cannot be negative.");
        }
        return new self($newAmount);
    }

    public function isLessThan(self $other): bool
    {
        return $this->amountRial < $other->amountRial;
    }

    public function isGreaterThanOrEqual(self $other): bool
    {
        return $this->amountRial >= $other->amountRial;
    }
}

