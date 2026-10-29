<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Core\Protocol;

use Itsjustmoha\X402\Core\Asset\AssetRegistry;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;
use Itsjustmoha\X402\Core\Protocol\PaymentRequirements;
use Itsjustmoha\X402\Tests\Core\Support\Fixtures;
use Itsjustmoha\X402\Tests\Core\Support\SpecExamples;
use PHPUnit\Framework\TestCase;

final class PaymentRequirementsTest extends TestCase
{
    public function test_exact_evm_carries_the_eip712_domain_in_extra(): void
    {
        self::assertSame([
            'scheme' => 'exact',
            'network' => 'eip155:84532',
            'amount' => '10000',
            'asset' => SpecExamples::BASE_SEPOLIA_USDC,
            'payTo' => SpecExamples::PAY_TO,
            'maxTimeoutSeconds' => 60,
            'extra' => ['name' => 'USDC', 'version' => '2'],
        ], Fixtures::specRequirements()->toArray());
    }

    public function test_matches_an_identical_accepted_object_regardless_of_key_order(): void
    {
        $accepted = array_reverse(Fixtures::specRequirements()->toArray(), true);

        self::assertTrue(Fixtures::specRequirements()->isMatchedBy($accepted));
    }

    public function test_allows_additive_client_extra_but_not_changed_values(): void
    {
        $requirements = Fixtures::specRequirements();
        $accepted = $requirements->toArray();

        $accepted['extra']['assetTransferMethod'] = 'eip3009';
        self::assertTrue($requirements->isMatchedBy($accepted));

        $accepted['extra']['name'] = 'Fake USDC';
        self::assertFalse($requirements->isMatchedBy($accepted));
    }

    public function test_rejects_any_changed_or_added_core_field(): void
    {
        $requirements = Fixtures::specRequirements();
        $mutations = [
            'amount' => '1',
            'payTo' => '0x0000000000000000000000000000000000000001',
            'asset' => '0x0000000000000000000000000000000000000002',
            'network' => 'eip155:8453',
            'scheme' => 'upto',
            'maxTimeoutSeconds' => 3600,
        ];

        foreach ($mutations as $field => $value) {
            $accepted = $requirements->toArray();
            $accepted[$field] = $value;
            self::assertFalse($requirements->isMatchedBy($accepted), $field);
        }

        $accepted = $requirements->toArray();
        $accepted['amount'] = 10000;
        self::assertFalse($requirements->isMatchedBy($accepted), 'amount must stay a string');

        $accepted = $requirements->toArray();
        $accepted['unexpected'] = 'x';
        self::assertFalse($requirements->isMatchedBy($accepted), 'unknown core field');

        $accepted = $requirements->toArray();
        unset($accepted['extra']);
        self::assertFalse($requirements->isMatchedBy($accepted), 'missing extra');
    }

    public function test_rejects_invalid_pay_to_addresses(): void
    {
        $asset = (new AssetRegistry)->assetFor('eip155:84532');

        foreach (['', 'not-an-address', '0x123', '0x0000000000000000000000000000000000000000', SpecExamples::PAY_TO.'00'] as $payTo) {
            try {
                PaymentRequirements::exactEvm('eip155:84532', $asset, '10000', $payTo, 60);
                self::fail(sprintf('pay_to "%s" should have been rejected.', $payTo));
            } catch (InvalidConfiguration) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_non_positive_amounts_and_timeouts(): void
    {
        foreach ([['0', 60], ['-1', 60], ['1.5', 60], ['10', 0]] as [$amount, $timeout]) {
            try {
                new PaymentRequirements('exact', 'eip155:84532', $amount, SpecExamples::BASE_SEPOLIA_USDC, SpecExamples::PAY_TO, $timeout);
                self::fail(sprintf('amount "%s" / timeout %d should have been rejected.', $amount, $timeout));
            } catch (InvalidConfiguration) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
