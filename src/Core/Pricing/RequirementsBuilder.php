<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Pricing;

use Itsjustmoha\X402\Core\Asset\AssetRegistry;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;
use Itsjustmoha\X402\Core\Exception\InvalidPrice;
use Itsjustmoha\X402\Core\Protocol\PaymentRequirements;

/**
 * Turns a human price ("$0.01") on a network into `exact` EVM payment
 * requirements, using the network's default stablecoin.
 */
final readonly class RequirementsBuilder
{
    public function __construct(private AssetRegistry $assets) {}

    /**
     * @throws InvalidConfiguration
     * @throws InvalidPrice
     */
    public function build(string $price, string $network, string $payTo, int $maxTimeoutSeconds): PaymentRequirements
    {
        $asset = $this->assets->assetFor($network);

        return PaymentRequirements::exactEvm(
            $network,
            $asset,
            Price::toAtomicUnits($price, $asset->decimals),
            $payTo,
            $maxTimeoutSeconds,
        );
    }
}
