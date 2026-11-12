<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Decimal-safe money helper. Values are carried as integer paisa (1 BDT = 100 paisa).
 * No floats anywhere; strings in/out are DECIMAL(14,2) compatible ("12500.00").
 */
final class Money
{
    private function __construct(public readonly int $paisa) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function ofPaisa(int $paisa): self
    {
        return new self($paisa);
    }

    /** Parse "12500", "12500.5", "-1.25", 125 → Money. Rejects floats and malformed input. */
    public static function of(string|int $value): self
    {
        if (is_int($value)) {
            return new self($value * 100);
        }
        $value = trim($value);
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid money value: {$value}");
        }
        $neg = ($m[1] ?? '') === '-';
        $int = (int) $m[2];
        $frac = str_pad($m[3] ?? '0', 2, '0');
        $paisa = $int * 100 + (int) $frac;

        return new self($neg ? -$paisa : $paisa);
    }

    public function add(self $other): self
    {
        return new self($this->paisa + $other->paisa);
    }

    public function sub(self $other): self
    {
        return new self($this->paisa - $other->paisa);
    }

    /** Multiply by a quantity given as decimal string with up to 3 places (quantity decimal(14,3)); rounds half-up. */
    public function mulQty(string $qty): self
    {
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,3}))?$/', trim($qty), $m)) {
            throw new InvalidArgumentException("Invalid quantity: {$qty}");
        }
        $neg = ($m[1] ?? '') === '-';
        $milli = (int) $m[2] * 1000 + (int) str_pad($m[3] ?? '0', 3, '0');
        $product = $this->paisa * $milli; // paisa * milli-units
        $rounded = intdiv(abs($product) + 500, 1000) * ($product < 0 ? -1 : 1);

        return new self($neg ? -$rounded : $rounded);
    }

    /** Percentage with up to 2 decimals ("5", "12.5"); rounds half-up at paisa level (BR-001 line-level rounding). */
    public function percent(string $percent): self
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($percent), $m)) {
            throw new InvalidArgumentException("Invalid percent: {$percent}");
        }
        $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0'); // basis points
        $product = $this->paisa * $bp; // paisa * bp; divide by 10000
        $rounded = intdiv(abs($product) + 5000, 10000) * ($product < 0 ? -1 : 1);

        return new self($rounded);
    }

    public function isNegative(): bool
    {
        return $this->paisa < 0;
    }

    public function isZero(): bool
    {
        return $this->paisa === 0;
    }

    public function compare(self $other): int
    {
        return $this->paisa <=> $other->paisa;
    }

    /** Decimal string "12500.00" for DB/JSON. */
    public function toDecimal(): string
    {
        $abs = abs($this->paisa);

        return ($this->paisa < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Lakh/crore grouping: "৳ 1,25,000.00". */
    public function format(bool $symbol = true): string
    {
        $abs = abs($this->paisa);
        $int = (string) intdiv($abs, 100);
        $frac = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return ($this->paisa < 0 ? '-' : '').($symbol ? '৳ ' : '').self::groupLakhCrore($int).'.'.$frac;
    }

    public static function groupLakhCrore(string $int): string
    {
        if (strlen($int) <= 3) {
            return $int;
        }
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        $groups = [];
        while (strlen($rest) > 2) {
            array_unshift($groups, substr($rest, -2));
            $rest = substr($rest, 0, -2);
        }
        if ($rest !== '') {
            array_unshift($groups, $rest);
        }

        return implode(',', $groups).','.$last3;
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }
}
