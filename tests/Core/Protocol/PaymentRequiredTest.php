<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Core\Protocol;

use Itsjustmoha\X402\Core\Codec\HeaderCodec;
use Itsjustmoha\X402\Core\Discovery\BazaarExtension;
use Itsjustmoha\X402\Core\Protocol\PaymentRequired;
use Itsjustmoha\X402\Core\Protocol\PaymentRequirements;
use Itsjustmoha\X402\Core\Protocol\ResourceInfo;
use Itsjustmoha\X402\Tests\Core\Support\Fixtures;
use Itsjustmoha\X402\Tests\Core\Support\SpecExamples;
use PHPUnit\Framework\TestCase;

final class PaymentRequiredTest extends TestCase
{
    public function test_encodes_byte_for_byte_like_the_specification_example(): void
    {
        $required = Fixtures::specOffer()->withError('PAYMENT-SIGNATURE header is required');

        self::assertSame(SpecExamples::PAYMENT_REQUIRED, HeaderCodec::encode($required->toArray()));
    }

    public function test_omits_empty_extensions_and_includes_declared_ones(): void
    {
        self::assertArrayNotHasKey('extensions', Fixtures::specOffer()->toArray());

        $withBazaar = Fixtures::specOffer(BazaarExtension::forHttp('GET', ['input' => ['city' => 'Berlin']]))->toArray();

        self::assertSame('Berlin', $withBazaar['extensions']['bazaar']['info']['input']['queryParams']['city']);
    }

    public function test_converts_to_the_v1_body_with_legacy_network_names(): void
    {
        $v1 = Fixtures::specOffer(BazaarExtension::forHttp('GET', []))->toV1Array();

        self::assertNotNull($v1);
        self::assertSame(1, $v1['x402Version']);
        self::assertSame('X-PAYMENT header is required', $v1['error']);
        self::assertSame([
            'scheme' => 'exact',
            'network' => 'base-sepolia',
            'maxAmountRequired' => '10000',
            'resource' => SpecExamples::RESOURCE_URL,
            'description' => 'Access to premium market data',
            'mimeType' => 'application/json',
            'payTo' => SpecExamples::PAY_TO,
            'maxTimeoutSeconds' => 60,
            'asset' => SpecExamples::BASE_SEPOLIA_USDC,
            'extra' => ['name' => 'USDC', 'version' => '2'],
        ], array_diff_key($v1['accepts'][0], ['outputSchema' => true]));
        self::assertTrue($v1['accepts'][0]['outputSchema']['input']['discoverable']);
        self::assertSame('GET', $v1['accepts'][0]['outputSchema']['input']['method']);
    }

    public function test_v1_body_is_null_when_no_network_has_a_legacy_name(): void
    {
        $arbitrum = new PaymentRequirements('exact', 'eip155:421614', '10000', '0x75faf114eafb1BDbe2F0316DF893fd58CE46AA4d', SpecExamples::PAY_TO, 60);
        $required = new PaymentRequired(ResourceInfo::create('https://api.example.com/x'), [$arbitrum]);

        self::assertNull($required->toV1Array());
        self::assertNull($required->v1RequirementsFor($arbitrum));
    }
}
