<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Pricing;

use Itsjustmoha\X402\Core\Exception\InvalidPrice;

/**
 * Exact conversion of human prices ("$0.01", "0.01", "2") into atomic token
 * units ("10000" for USDC with 6 decimals).
 *
 * The conversion is purely string based: no floats, no bcmath, no gmp. A price
 * with more significant decimal places than the asset supports is rejected
 * instead of being rounded, because silently charging a different amount than
 * the one configured is a bug.
 */
final class Price
{
    /** uint256 has at most 78 decimal digits. */
    private const int MAX_ATOMIC_DIGITS = 78;

    private const int MAX_DECIMALS = 36;

    private function __construct() {}

    /**
     * @return non-empty-string atomic units without leading zeros, always > 0
     *
     * @throws InvalidPrice
     */
    public static function toAtomicUnits(string $price, int $decimals): string
    {
        if ($decimals < 0 || $decimals > self::MAX_DECIMALS) {
            throw new InvalidPrice(sprintf('Asset decimals must be between 0 and %d, got %d.', self::MAX_DECIMALS, $decimals));
        }

        $normalized = trim($price);
        if (str_starts_with($normalized, '$')) {
            $normalized = substr($normalized, 1);
        }

        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $normalized, $matches) !== 1) {
            throw new InvalidPrice(sprintf(
                'Invalid price "%s". Use a plain decimal like "0.01" or "$0.01" (no signs, exponents or thousands separators).',
                $price,
            ));
        }

        $integerPart = $matches[1];
        $fraction = rtrim($matches[2] ?? '', '0');

        if (strlen($fraction) > $decimals) {
            throw new InvalidPrice(sprintf(
                'Price "%s" has %d decimal places but the asset only supports %d.',
                $price,
                strlen($fraction),
                $decimals,
            ));
        }

        $atomic = ltrim($integerPart.str_pad($fraction, $decimals, '0'), '0');

        if ($atomic === '') {
            throw new InvalidPrice(sprintf('Price "%s" must be greater than zero.', $price));
        }

        if (strlen($atomic) > self::MAX_ATOMIC_DIGITS) {
            throw new InvalidPrice(sprintf('Price "%s" exceeds the uint256 range.', $price));
        }

        return $atomic;
    }
}
