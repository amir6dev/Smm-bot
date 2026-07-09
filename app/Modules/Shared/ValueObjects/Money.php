<?php

declare(strict_types=1);

namespace App\Modules\Shared\ValueObjects;

use InvalidArgumentException;

final readonly class Money
{
    public function __construct(
        public int $amountRial
    ) {
    }

    public static function fromToman(int $toman): self
    {
        return new self($toman * 10);
    }

    public function toToman(): int
    {
        return (int) ($this->amountRial / 10);
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
