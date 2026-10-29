<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Asset;

use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;

/**
 * An EIP-3009 capable ERC-20 token (e.g. USDC) on one EVM network.
 *
 * `name` and `version` are the token's EIP-712 domain values. Clients need them
 * to sign `transferWithAuthorization`, so they are sent in
 * PaymentRequirements.extra (scheme_exact_evm.md, "extra field definitions").
 */
final readonly class Asset
{
    /**
     * @throws InvalidConfiguration
     */
    public function __construct(
        public string $address,
        public int $decimals,
        public string $name,
        public string $version,
        public string $symbol,
    ) {
        if (preg_match('/^0x[0-9a-fA-F]{40}$/D', $address) !== 1) {
            throw new InvalidConfiguration(sprintf('Asset address "%s" is not a 0x-prefixed 20-byte hex address.', $address));
        }

        if ($decimals < 0 || $decimals > 36) {
            throw new InvalidConfiguration(sprintf('Asset decimals must be between 0 and 36, got %d.', $decimals));
        }

        if ($name === '' || $version === '') {
            throw new InvalidConfiguration('Asset EIP-712 domain name and version must not be empty.');
        }
    }

    /**
     * @return array{name: string, version: string}
     */
    public function eip712Domain(): array
    {
        return ['name' => $this->name, 'version' => $this->version];
    }
}
