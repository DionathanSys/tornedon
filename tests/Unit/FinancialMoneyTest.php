<?php

namespace Tests\Unit;

use App\Support\Financial\Money;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialMoneyTest extends TestCase
{
    public static function amounts(): array
    {
        return [['0.29', 29], ['4600.29', 460029], ['-100.28', -10028], ['999999999999.99', 99999999999999], ['0.00', 0]];
    }

    #[DataProvider('amounts')]
    public function test_decimal_round_trip_is_exact(string $decimal, int $cents): void
    {
        $this->assertSame($cents, Money::cents($decimal, true));
        $this->assertSame($decimal, Money::decimal($cents));
    }

    public function test_strict_input_never_silently_rounds_fractional_cents(): void
    {
        $this->expectException(ValidationException::class);
        Money::cents('0.291', true);
    }

    public function test_legacy_rounding_remains_half_up_and_proportions_never_overflow_to_float(): void
    {
        $this->assertSame(30, Money::cents('0.295'));
        $this->assertSame(-30, Money::cents('-0.295'));
        $this->assertSame(99999999999999, Money::proportion(99999999999999, 99999999999999, 99999999999999));
        $this->assertSame(33, Money::proportion(100, 1, 3));
        $this->assertSame(34, Money::proportion(101, 1, 3));
        $this->assertSame(-50, Money::proportion(-100, 1, 2));
    }
}
