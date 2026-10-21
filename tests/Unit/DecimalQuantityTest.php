<?php

namespace Tests\Unit;

use App\Support\DecimalQuantity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalQuantityTest extends TestCase
{
    #[DataProvider('quantityCases')]
    public function test_decimal_quantity_round_trips_exactly(string $input, string $expected): void
    {
        self::assertSame($expected, DecimalQuantity::fromMilli(DecimalQuantity::toMilli($input)));
    }

    public static function quantityCases(): array
    {
        return [
            'integer' => ['7', '7.000'],
            'one decimal' => ['1.2', '1.200'],
            'two decimals' => ['0.25', '0.250'],
            'three decimals' => ['12.375', '12.375'],
            'leading zeros' => ['0002.005', '2.005'],
        ];
    }

    public function test_more_than_three_decimal_places_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalQuantity::toMilli('1.2345');
    }
}
