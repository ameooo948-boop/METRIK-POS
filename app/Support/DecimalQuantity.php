<?php

namespace App\Support;

use InvalidArgumentException;

/** Exact arithmetic for quantities stored with three decimal places. */
final class DecimalQuantity
{
    public static function toMilli(string|int $value): int
    {
        $value = trim((string) $value);

        if (! preg_match('/^(\d+)(?:\.(\d{1,3}))?$/D', $value, $matches)) {
            throw new InvalidArgumentException('Quantity must be a non-negative decimal with at most three decimal places.');
        }

        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 3, '0');

        return ($whole * 1000) + $fraction;
    }

    public static function fromMilli(int $milli): string
    {
        $negative = $milli < 0;
        $absolute = abs($milli);
        $whole = intdiv($absolute, 1000);
        $fraction = str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }
}
