<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_parses_exact_decimal_strings_into_nanodollars(): void
    {
        $this->assertSame(46_125_000, Money::fromDecimal('0.046125000'));
        $this->assertSame(5_000_000_000, Money::fromDecimal('5.00'));
        $this->assertSame(1, Money::fromDecimal('0.000000001'));
        $this->assertSame(-1_500_000_000, Money::fromDecimal('-1.5'));
    }

    public function test_it_refuses_anything_that_is_not_an_exact_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimal('0.0000000001'); // 10 decimals: finer than a nanodollar
    }

    public function test_it_formats_without_rounding(): void
    {
        $this->assertSame('$0.046125', Money::format(46_125_000));
        $this->assertSame('$10.00', Money::format(10_000_000_000));
        $this->assertSame('$0.000000001', Money::format(1));
        $this->assertSame('-$1,234.50', Money::format(-1_234_500_000_000));
        $this->assertSame('0.373875000', Money::toDecimal(373_875_000));
    }
}
