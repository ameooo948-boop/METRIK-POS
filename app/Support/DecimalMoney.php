<?php

namespace App\Support;

use InvalidArgumentException;

/** Small exact-arithmetic helper for money stored with two decimal places. */
final class DecimalMoney
{
    public static function toCents(string|int $value): int
    {
        $value = trim((string) $value);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $value, $matches)) {
            throw new InvalidArgumentException('Money value must be a decimal number.');
        }

        $sign = ($matches[1] ?? '') === '-' ? -1 : 1;
        $whole = (int) $matches[2];
        $fraction = $matches[3] ?? '';
        $twoDigits = (int) str_pad(substr($fraction, 0, 2), 2, '0');
        $thirdDigit = isset($fraction[2]) ? (int) $fraction[2] : 0;
        $cents = ($whole * 100) + $twoDigits;

        if ($thirdDigit >= 5) {
            $cents++;
        }

        return $sign * $cents;
    }

    public static function fromCents(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        $whole = intdiv($absolute, 100);
        $fraction = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }
}
