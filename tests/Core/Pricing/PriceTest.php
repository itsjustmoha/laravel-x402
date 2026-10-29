<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Core\Pricing;

use Itsjustmoha\X402\Core\Exception\InvalidPrice;
use Itsjustmoha\X402\Core\Pricing\Price;
use PHPUnit\Framework\TestCase;

final class PriceTest extends TestCase
{
    public function test_converts_dollar_and_plain_decimal_prices_to_usdc_atomic_units(): void
    {
        $cases = [
            ['$0.01', '10000'],
            ['0.01', '10000'],
            ['$1', '1000000'],
            ['1', '1000000'],
            ['1.5', '1500000'],
            ['0.000001', '1'],
            ['$0.001', '1000'],
            ['0.0100000', '10000'],
            ['  $0.25 ', '250000'],
            ['123456789.123456', '123456789123456'],
            ['10.000000', '10000000'],
        ];

        foreach ($cases as [$price, $expected]) {
            self::assertSame($expected, Price::toAtomicUnits($price, 6), sprintf('price "%s"', $price));
        }
    }

    public function test_supports_other_decimals(): void
    {
        self::assertSame('1', Price::toAtomicUnits('0.000000000000000001', 18));
        self::assertSame('1500000000000000000', Price::toAtomicUnits('1.5', 18));
        self::assertSame('7', Price::toAtomicUnits('7', 0));
    }

    public function test_rejects_more_precision_than_the_asset_supports(): void
    {
        $this->expectException(InvalidPrice::class);
        $this->expectExceptionMessage('has 7 decimal places but the asset only supports 6');

        Price::toAtomicUnits('0.0000001', 6);
    }

    public function test_rejects_fractions_for_zero_decimal_assets(): void
    {
        $this->expectException(InvalidPrice::class);

        Price::toAtomicUnits('1.5', 0);
    }

    public function test_rejects_malformed_zero_and_negative_prices(): void
    {
        $invalid = ['', '$', '0', '$0.00', '-1', '-$1', '1e-3', '1,000', '.5', '01.5', '1.', 'abc', '$$1', '0x10', '1 000', 'NaN', 'INF'];

        foreach ($invalid as $price) {
            try {
                Price::toAtomicUnits($price, 6);
                self::fail(sprintf('Price "%s" should have been rejected.', $price));
            } catch (InvalidPrice) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_amounts_beyond_uint256(): void
    {
        $this->expectException(InvalidPrice::class);

        Price::toAtomicUnits(str_repeat('9', 80), 6);
    }

    public function test_rejects_unsupported_decimals(): void
    {
        $this->expectException(InvalidPrice::class);

        Price::toAtomicUnits('1', -1);
    }
}
