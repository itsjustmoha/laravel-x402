<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Core\Protocol;

use Itsjustmoha\X402\Core\Codec\HeaderCodec;
use Itsjustmoha\X402\Core\Exception\InvalidPaymentHeader;
use Itsjustmoha\X402\Core\Protocol\PaymentPayload;
use Itsjustmoha\X402\Core\Protocol\ProtocolVersion;
use Itsjustmoha\X402\Tests\Core\Support\Fixtures;
use Itsjustmoha\X402\Tests\Core\Support\SpecExamples;
use PHPUnit\Framework\TestCase;

final class PaymentPayloadTest extends TestCase
{
    public function test_parses_the_v2_specification_example(): void
    {
        $payment = PaymentPayload::fromHeader(SpecExamples::PAYMENT_SIGNATURE);

        self::assertSame(ProtocolVersion::V2, $payment->version);
        self::assertSame('exact', $payment->scheme);
        self::assertSame('eip155:84532', $payment->network);
        self::assertTrue(Fixtures::specRequirements()->isMatchedBy($payment->accepted));
        self::assertSame(SpecExamples::PAYER, $payment->claimedPayer());
        self::assertSame(base64_decode(SpecExamples::PAYMENT_SIGNATURE, true), $payment->json);
    }

    public function test_parses_the_v1_specification_example(): void
    {
        $payment = PaymentPayload::fromHeader(SpecExamples::V1_X_PAYMENT);

        self::assertSame(ProtocolVersion::V1, $payment->version);
        self::assertSame('base-sepolia', $payment->network);
        self::assertSame([], $payment->accepted);
        self::assertSame(SpecExamples::PAYER, $payment->claimedPayer());
    }

    public function test_keeps_the_original_json_verbatim_for_the_facilitator(): void
    {
        $json = '{"x402Version":2,"accepted":{"scheme":"exact","network":"eip155:84532"},"payload":{"big":123456789012345678901234567890,"empty":{}},"extensions":{}}';

        self::assertSame($json, PaymentPayload::fromHeader(base64_encode($json))->json);
    }

    public function test_fingerprint_ignores_key_order_but_not_content(): void
    {
        $a = PaymentPayload::fromHeader(Fixtures::v2Header(Fixtures::specRequirements()->toArray()));
        $reordered = base64_encode((string) json_encode([
            'payload' => array_reverse(Fixtures::schemePayload('0xf3746613c2d920b5fdabc0856f2aeb2d4f88ee6037b8cc5d04a71a4462f13480'), true),
            'accepted' => Fixtures::specRequirements()->toArray(),
            'x402Version' => 2,
        ]));
        $otherNonce = PaymentPayload::fromHeader(Fixtures::v2Header(Fixtures::specRequirements()->toArray(), '0x'.str_repeat('ab', 32)));

        self::assertSame($a->fingerprint(), PaymentPayload::fromHeader($reordered)->fingerprint());
        self::assertNotSame($a->fingerprint(), $otherNonce->fingerprint());
    }

    public function test_rejects_unsupported_versions(): void
    {
        foreach ([3, 0, '2', null] as $version) {
            try {
                PaymentPayload::fromHeader(HeaderCodec::encode(['x402Version' => $version, 'accepted' => ['scheme' => 'exact', 'network' => 'n'], 'payload' => ['a' => 1]]));
                self::fail('version '.var_export($version, true).' should have been rejected');
            } catch (InvalidPaymentHeader $exception) {
                self::assertSame(InvalidPaymentHeader::INVALID_VERSION, $exception->errorCode);
            }
        }
    }

    public function test_rejects_structurally_invalid_payloads(): void
    {
        $invalid = [
            'v2 without accepted' => ['x402Version' => 2, 'payload' => ['a' => 1]],
            'v2 accepted is a list' => ['x402Version' => 2, 'accepted' => ['exact'], 'payload' => ['a' => 1]],
            'v2 without scheme' => ['x402Version' => 2, 'accepted' => ['network' => 'eip155:84532'], 'payload' => ['a' => 1]],
            'v2 payload is a string' => ['x402Version' => 2, 'accepted' => ['scheme' => 'exact', 'network' => 'eip155:84532'], 'payload' => '0xabc'],
            'v1 without network' => ['x402Version' => 1, 'scheme' => 'exact', 'payload' => ['a' => 1]],
        ];

        foreach ($invalid as $label => $data) {
            try {
                PaymentPayload::fromHeader(HeaderCodec::encode($data));
                self::fail($label.' should have been rejected');
            } catch (InvalidPaymentHeader $exception) {
                self::assertSame(InvalidPaymentHeader::INVALID_PAYLOAD, $exception->errorCode, $label);
            }
        }
    }
}
