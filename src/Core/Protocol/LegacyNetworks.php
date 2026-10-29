<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Protocol;

/**
 * x402 v1 used plain network names ("base-sepolia") where v2 uses CAIP-2 ids
 * ("eip155:84532").
 *
 * Source: coinbase/x402 at commit 0e6e8c55d86b4f14234fb6e49a4052074fb93e57,
 * typescript/packages/mechanisms/evm/src/constants.ts (EVM_NETWORK_CHAIN_ID_MAP).
 */
final class LegacyNetworks
{
    /** @var array<string, int> */
    private const array CHAIN_IDS = [
        'ethereum' => 1,
        'sepolia' => 11155111,
        'abstract' => 2741,
        'abstract-testnet' => 11124,
        'base-sepolia' => 84532,
        'base' => 8453,
        'avalanche-fuji' => 43113,
        'avalanche' => 43114,
        'iotex' => 4689,
        'sei' => 1329,
        'sei-testnet' => 1328,
        'polygon' => 137,
        'polygon-amoy' => 80002,
        'peaq' => 3338,
        'story' => 1514,
        'educhain' => 41923,
        'skale-base-sepolia' => 324705682,
        'megaeth' => 4326,
        'monad' => 143,
        'monad-testnet' => 10143,
        'stable' => 988,
        'stable-testnet' => 2201,
        'celo' => 42220,
        'flare' => 14,
    ];

    private function __construct() {}

    /**
     * @return string|null the v1 name for a CAIP-2 network, or null when v1 never had one
     */
    public static function nameFor(string $caip2Network): ?string
    {
        $name = array_search($caip2Network, array_map(
            static fn (int $chainId): string => 'eip155:'.$chainId,
            self::CHAIN_IDS,
        ), true);

        return is_string($name) ? $name : null;
    }
}
