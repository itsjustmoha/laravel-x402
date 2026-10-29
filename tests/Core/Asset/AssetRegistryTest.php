<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Core\Asset;

use Itsjustmoha\X402\Core\Asset\AssetRegistry;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;
use Itsjustmoha\X402\Tests\Core\Support\SpecExamples;
use PHPUnit\Framework\TestCase;

final class AssetRegistryTest extends TestCase
{
    public function test_knows_usdc_on_base_sepolia_and_base(): void
    {
        $registry = new AssetRegistry;

        $sepolia = $registry->assetFor('eip155:84532');
        self::assertSame(SpecExamples::BASE_SEPOLIA_USDC, $sepolia->address);
        self::assertSame(6, $sepolia->decimals);
        self::assertSame(['name' => 'USDC', 'version' => '2'], $sepolia->eip712Domain());
        self::assertTrue($registry->isTestnet('eip155:84532'));

        $base = $registry->assetFor('eip155:8453');
        self::assertSame('0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', $base->address);
        self::assertSame(['name' => 'USD Coin', 'version' => '2'], $base->eip712Domain());
        self::assertFalse($registry->isTestnet('eip155:8453'));
    }

    public function test_overrides_and_additions(): void
    {
        $registry = new AssetRegistry([
            'eip155:43113' => ['address' => '0x5425890298aed601595a70AB815c96711a31Bc65', 'decimals' => 6, 'name' => 'USD Coin', 'version' => '2', 'testnet' => true],
        ]);

        self::assertSame('0x5425890298aed601595a70AB815c96711a31Bc65', $registry->assetFor('eip155:43113')->address);
        self::assertTrue($registry->isTestnet('eip155:43113'));
        self::assertFalse($registry->isTestnet('eip155:999999'));
    }

    public function test_rejects_unknown_and_non_evm_networks(): void
    {
        foreach (['eip155:999999', 'base-sepolia', 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp', 'eip155:', ''] as $network) {
            try {
                (new AssetRegistry)->assetFor($network);
                self::fail($network.' should have been rejected');
            } catch (InvalidConfiguration) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_invalid_overrides(): void
    {
        $invalid = [
            ['eip155:1' => ['address' => 'nope', 'decimals' => 6, 'name' => 'X', 'version' => '1']],
            ['eip155:1' => ['address' => SpecExamples::BASE_SEPOLIA_USDC, 'decimals' => 6, 'name' => 'X']],
            ['solana:devnet' => ['address' => SpecExamples::BASE_SEPOLIA_USDC, 'decimals' => 6, 'name' => 'X', 'version' => '1']],
            ['eip155:1' => 'not-an-array'],
        ];

        foreach ($invalid as $overrides) {
            try {
                new AssetRegistry($overrides);
                self::fail('override should have been rejected: '.json_encode($overrides));
            } catch (InvalidConfiguration) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
