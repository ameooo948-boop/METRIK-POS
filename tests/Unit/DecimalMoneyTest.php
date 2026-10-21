<?php

namespace Tests\Unit;

use App\Support\DecimalMoney;
use PHPUnit\Framework\TestCase;

class DecimalMoneyTest extends TestCase
{
    public function test_decimal_money_conversion_uses_integer_cents(): void
    {
        $this->assertSame(2188, DecimalMoney::toCents('21.88'));
        $this->assertSame('21.88', DecimalMoney::fromCents(2188));
        $this->assertSame('0.05', DecimalMoney::fromCents(DecimalMoney::toCents('0.05')));
    }

    public function test_money_sum_with_extra_zero_decimal_places_is_safe(): void
    {
        $this->assertSame(1250, DecimalMoney::toCents('12.5000'));
    }
}
