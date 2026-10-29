<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Core\Support;

use Itsjustmoha\X402\Core\Asset\AssetRegistry;
use Itsjustmoha\X402\Core\Codec\HeaderCodec;
use Itsjustmoha\X402\Core\Protocol\PaymentRequired;
use Itsjustmoha\X402\Core\Protocol\PaymentRequirements;
use Itsjustmoha\X402\Core\Protocol\ResourceInfo;

final class Fixtures
{
    public const string FACILITATOR_URL = 'https://facilitator.test';

    public const string TX_HASH = '0x1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef';

    /**
     * The offer used in the specification examples: 0.01 USDC on Base Sepolia.
     *
     * @param  array<string, mixed>  $extensions
     */
    public static function specOffer(array $extensions = []): PaymentRequired
    {
        return new PaymentRequired(
            ResourceInfo::create(SpecExamples::RESOURCE_URL, 'Access to premium market data', 'application/json'),
            [self::specRequirements()],
            $extensions,
        );
    }

    public static function specRequirements(): PaymentRequirements
    {
        return PaymentRequirements::exactEvm(
            'eip155:84532',
            (new AssetRegistry)->assetFor('eip155:84532'),
            '10000',
            SpecExamples::PAY_TO,
            60,
        );
    }

    /**
     * @param  array<string, mixed>  $accepted
     */
    public static function v2Header(array $accepted, string $nonce = '0xf3746613c2d920b5fdabc0856f2aeb2d4f88ee6037b8cc5d04a71a4462f13480'): string
    {
        return HeaderCodec::encode([
            'x402Version' => 2,
            'accepted' => $accepted,
            'payload' => self::schemePayload($nonce),
        ]);
    }

    public static function v1Header(string $network = 'base-sepolia', string $nonce = '0xf3746613c2d920b5fdabc0856f2aeb2d4f88ee6037b8cc5d04a71a4462f13480'): string
    {
        return HeaderCodec::encode([
            'x402Version' => 1,
            'scheme' => 'exact',
            'network' => $network,
            'payload' => self::schemePayload($nonce),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function schemePayload(string $nonce): array
    {
        return [
            'signature' => '0x2d6a7588d6acca505cbf0d9a4a227e0c52c6c34008c8e8986a1283259764173608a2ce6496642e377d6da8dbbf5836e9bd15092f9ecab05ded3d6293af148b571c',
            'authorization' => [
                'from' => SpecExamples::PAYER,
                'to' => SpecExamples::PAY_TO,
                'value' => '10000',
                'validAfter' => '1740672089',
                'validBefore' => '1740672154',
                'nonce' => $nonce,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function verifyValid(): array
    {
        return ['isValid' => true, 'payer' => SpecExamples::PAYER];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settleSuccess(): array
    {
        return ['success' => true, 'transaction' => self::TX_HASH, 'network' => 'eip155:84532', 'payer' => SpecExamples::PAYER];
    }
}
