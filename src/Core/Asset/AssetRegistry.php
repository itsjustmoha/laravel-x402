<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Asset;

use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;

/**
 * Default USD stablecoin per CAIP-2 network, used to turn "$0.01" into an
 * asset + atomic amount.
 *
 * Source of the built-in table: coinbase/x402 at commit
 * 0e6e8c55d86b4f14234fb6e49a4052074fb93e57,
 * typescript/packages/mechanisms/evm/src/defaultAssets.ts (see also
 * DEFAULT_ASSETS.md). Only EIP-3009 USDC entries are included; add other
 * networks or tokens through the constructor (config key `x402.assets`).
 */
final class AssetRegistry
{
    /**
     * @var array<string, array{address: string, decimals: int, name: string, version: string, symbol: string, testnet: bool}>
     */
    private const array DEFAULTS = [
        'eip155:84532' => ['address' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e', 'decimals' => 6, 'name' => 'USDC', 'version' => '2', 'symbol' => 'USDC', 'testnet' => true],
        'eip155:421614' => ['address' => '0x75faf114eafb1BDbe2F0316DF893fd58CE46AA4d', 'decimals' => 6, 'name' => 'USD Coin', 'version' => '2', 'symbol' => 'USDC', 'testnet' => true],
        'eip155:8453' => ['address' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', 'decimals' => 6, 'name' => 'USD Coin', 'version' => '2', 'symbol' => 'USDC', 'testnet' => false],
        'eip155:1' => ['address' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', 'decimals' => 6, 'name' => 'USD Coin', 'version' => '2', 'symbol' => 'USDC', 'testnet' => false],
        'eip155:137' => ['address' => '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359', 'decimals' => 6, 'name' => 'USD Coin', 'version' => '2', 'symbol' => 'USDC', 'testnet' => false],
        'eip155:42161' => ['address' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831', 'decimals' => 6, 'name' => 'USD Coin', 'version' => '2', 'symbol' => 'USDC', 'testnet' => false],
        'eip155:43114' => ['address' => '0xB97EF9Ef8734C71904D8002F8b6Bc66Dd9c48a6E', 'decimals' => 6, 'name' => 'USD Coin', 'version' => '2', 'symbol' => 'USDC', 'testnet' => false],
    ];

    /** @var array<string, Asset> */
    private array $assets = [];

    /** @var array<string, bool> */
    private array $testnets = [];

    /**
     * @param  array<array-key, mixed>  $overrides  network => [address, decimals, name, version, symbol?, testnet?]
     *
     * @throws InvalidConfiguration
     */
    public function __construct(array $overrides = [])
    {
        foreach (self::DEFAULTS as $network => $entry) {
            $this->register($network, $entry);
        }

        foreach ($overrides as $network => $entry) {
            $network = (string) $network;
            self::assertEvmNetwork($network);

            if (! is_array($entry)) {
                throw new InvalidConfiguration(sprintf('Asset override for "%s" must be an array.', $network));
            }

            $this->register($network, $entry);
        }
    }

    /**
     * @throws InvalidConfiguration
     */
    public function assetFor(string $network): Asset
    {
        self::assertEvmNetwork($network);

        return $this->assets[$network] ?? throw new InvalidConfiguration(sprintf(
            'No default asset is known for network "%s". Add it under the "assets" config key.',
            $network,
        ));
    }

    public function isTestnet(string $network): bool
    {
        return $this->testnets[$network] ?? false;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     *
     * @throws InvalidConfiguration
     */
    private function register(string $network, array $entry): void
    {
        foreach (['address', 'decimals', 'name', 'version'] as $key) {
            if (! isset($entry[$key]) || ! is_scalar($entry[$key])) {
                throw new InvalidConfiguration(sprintf('Asset entry for "%s" is missing "%s".', $network, $key));
            }
        }

        $symbol = $entry['symbol'] ?? 'USDC';

        $this->assets[$network] = new Asset(
            (string) $entry['address'],
            (int) $entry['decimals'],
            (string) $entry['name'],
            (string) $entry['version'],
            is_string($symbol) ? $symbol : 'USDC',
        );
        $this->testnets[$network] = (bool) ($entry['testnet'] ?? false);
    }

    /**
     * @throws InvalidConfiguration
     */
    private static function assertEvmNetwork(string $network): void
    {
        if (preg_match('/^eip155:[1-9][0-9]{0,31}$/D', $network) !== 1) {
            throw new InvalidConfiguration(sprintf(
                'Network "%s" is not supported. This version supports the "exact" scheme on EVM networks, given as CAIP-2 ids like "eip155:84532".',
                $network,
            ));
        }
    }
}
