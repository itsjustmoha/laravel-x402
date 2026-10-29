<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Core\Gate;

use Itsjustmoha\X402\Core\Codec\HeaderCodec;
use Itsjustmoha\X402\Core\Exception\TransportException;
use Itsjustmoha\X402\Core\Facilitator\FacilitatorClient;
use Itsjustmoha\X402\Core\Gate\BasicRequest;
use Itsjustmoha\X402\Core\Gate\Challenge;
use Itsjustmoha\X402\Core\Gate\PaymentGate;
use Itsjustmoha\X402\Core\Gate\Rejected;
use Itsjustmoha\X402\Core\Gate\Settled;
use Itsjustmoha\X402\Core\Gate\SettlementFailed;
use Itsjustmoha\X402\Core\Gate\Verified;
use Itsjustmoha\X402\Core\Http\HttpResponse;
use Itsjustmoha\X402\Core\Protocol\PaymentPayload;
use Itsjustmoha\X402\Core\Testing\FakeHttpTransport;
use Itsjustmoha\X402\Core\Testing\InMemoryPaymentLock;
use Itsjustmoha\X402\Tests\Core\Support\Fixtures;
use Itsjustmoha\X402\Tests\Core\Support\SpecExamples;
use PHPUnit\Framework\TestCase;

final class PaymentGateTest extends TestCase
{
    private FakeHttpTransport $transport;

    private InMemoryPaymentLock $lock;

    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->transport = new FakeHttpTransport;
        $this->lock = new InMemoryPaymentLock(fn (): int => $this->now);
    }

    public function test_request_without_payment_gets_a402_challenge(): void
    {
        $decision = $this->gate()->evaluate(Fixtures::specOffer(), new BasicRequest([]));

        self::assertInstanceOf(Challenge::class, $decision);
        self::assertSame(402, $decision->reply->status);
        self::assertSame('no-store', $decision->reply->headers['Cache-Control']);

        $header = HeaderCodec::decode($decision->reply->headers['PAYMENT-REQUIRED'])['data'];
        self::assertSame(2, $header['x402Version']);
        self::assertSame('Payment required', $header['error']);
        self::assertSame(Fixtures::specRequirements()->toArray(), $header['accepts'][0]);
        self::assertSame(SpecExamples::RESOURCE_URL, $header['resource']['url']);
        self::assertSame($header, $decision->reply->body, 'the JSON body mirrors the header');
        self::assertSame([], $this->transport->sent(), 'no facilitator call without payment');
    }

    public function test_valid_payment_is_verified_then_settled(): void
    {
        $this->transport->pushJson(Fixtures::verifyValid())->pushJson(Fixtures::settleSuccess());
        $gate = $this->gate();

        $verified = $gate->evaluate(Fixtures::specOffer(), $this->signed(SpecExamples::PAYMENT_SIGNATURE));

        self::assertInstanceOf(Verified::class, $verified);
        self::assertSame(SpecExamples::PAYER, $verified->payer());
        self::assertCount(1, $this->transport->sent());
        self::assertSame(Fixtures::specRequirements()->toArray(), json_decode((string) $this->transport->sent()[0]->body, true)['paymentRequirements']);

        $settled = $gate->settle($verified);

        self::assertInstanceOf(Settled::class, $settled);
        self::assertSame(SpecExamples::PAYMENT_RESPONSE_SUCCESS, $settled->headers['PAYMENT-RESPONSE']);
        self::assertSame(Fixtures::TX_HASH, $settled->settlement->transaction);
        self::assertSame('https://facilitator.test/settle', $this->transport->sent()[1]->url);
    }

    public function test_invalid_payment_is_rejected_with_the_facilitator_reason(): void
    {
        $this->transport->pushJson(['isValid' => false, 'invalidReason' => 'insufficient_funds', 'payer' => SpecExamples::PAYER]);

        $decision = $this->gate()->evaluate(Fixtures::specOffer(), $this->signed(SpecExamples::PAYMENT_SIGNATURE));

        self::assertInstanceOf(Rejected::class, $decision);
        self::assertSame('insufficient_funds', $decision->reason);
        self::assertSame(402, $decision->reply->status);
        self::assertSame(SpecExamples::PAYER, $decision->payer);
        self::assertSame('insufficient_funds', HeaderCodec::decode($decision->reply->headers['PAYMENT-REQUIRED'])['data']['error']);
        self::assertFalse($decision->isFacilitatorFailure());
        self::assertFalse($this->lock->isLocked($this->lockKey(SpecExamples::PAYMENT_SIGNATURE)), 'lock released after rejection');
    }

    public function test_malformed_header_is_rejected_without_calling_the_facilitator(): void
    {
        $decision = $this->gate()->evaluate(Fixtures::specOffer(), new BasicRequest(['PAYMENT-SIGNATURE' => 'not base64 at all!']));

        self::assertInstanceOf(Rejected::class, $decision);
        self::assertSame('invalid_payload', $decision->reason);
        self::assertSame(402, $decision->reply->status);
        self::assertArrayHasKey('PAYMENT-REQUIRED', $decision->reply->headers);
        self::assertSame([], $this->transport->sent());
    }

    public function test_payment_for_different_terms_is_rejected_locally(): void
    {
        $accepted = Fixtures::specRequirements()->toArray();
        $accepted['amount'] = '1';

        $decision = $this->gate()->evaluate(Fixtures::specOffer(), $this->signed(Fixtures::v2Header($accepted)));

        self::assertInstanceOf(Rejected::class, $decision);
        self::assertSame('No matching payment requirements', $decision->reason);
        self::assertSame([], $this->transport->sent());
    }

    public function test_v1_payment_is_rejected_when_v1_compatibility_is_off(): void
    {
        $decision = $this->gate()->evaluate(Fixtures::specOffer(), new BasicRequest(['X-PAYMENT' => SpecExamples::V1_X_PAYMENT]));

        self::assertInstanceOf(Rejected::class, $decision);
        self::assertSame('invalid_x402_version', $decision->reason);
        self::assertSame(2, $decision->reply->body['x402Version']);
        self::assertSame([], $this->transport->sent());
    }

    public function test_facilitator_outage_during_verify_is_a502_and_releases_the_lock(): void
    {
        $this->transport->push(new TransportException('Connection refused'));

        $decision = $this->gate()->evaluate(Fixtures::specOffer(), $this->signed(SpecExamples::PAYMENT_SIGNATURE));

        self::assertInstanceOf(Rejected::class, $decision);
        self::assertTrue($decision->isFacilitatorFailure());
        self::assertSame(502, $decision->reply->status);
        self::assertSame(['error' => 'facilitator_unavailable'], $decision->reply->body);
        self::assertArrayNotHasKey('PAYMENT-REQUIRED', $decision->reply->headers);
        self::assertFalse($this->lock->isLocked($this->lockKey(SpecExamples::PAYMENT_SIGNATURE)));
    }

    public function test_concurrent_use_of_the_same_payment_is_rejected_as_duplicate(): void
    {
        $this->transport->pushJson(Fixtures::verifyValid());
        $gate = $this->gate();

        $first = $gate->evaluate(Fixtures::specOffer(), $this->signed(SpecExamples::PAYMENT_SIGNATURE));
        $second = $gate->evaluate(Fixtures::specOffer(), $this->signed(SpecExamples::PAYMENT_SIGNATURE));

        self::assertInstanceOf(Verified::class, $first);
        self::assertInstanceOf(Rejected::class, $second);
        self::assertSame('duplicate_payment', $second->reason);
        self::assertCount(1, $this->transport->sent(), 'the duplicate never reached the facilitator');
    }

    public function test_settled_payment_stays_locked_until_its_authorization_window_has_passed(): void
    {
        $this->transport->pushJson(Fixtures::verifyValid())->pushJson(Fixtures::settleSuccess());
        $gate = $this->gate();
        $verified = $gate->evaluate(Fixtures::specOffer(), $this->signed(SpecExamples::PAYMENT_SIGNATURE));
        self::assertInstanceOf(Verified::class, $verified);
        self::assertInstanceOf(Settled::class, $gate->settle($verified));

        $replay = $gate->evaluate(Fixtures::specOffer(), $this->signed(SpecExamples::PAYMENT_SIGNATURE));
        self::assertInstanceOf(Rejected::class, $replay);
        self::assertSame('duplicate_payment', $replay->reason);

        $this->now += 60 + 60 + 1;
        self::assertFalse($this->lock->isLocked($this->lockKey(SpecExamples::PAYMENT_SIGNATURE)));
    }

    public function test_abandon_releases_the_lock_so_the_client_can_retry(): void
    {
        $this->transport->pushJson(Fixtures::verifyValid())->pushJson(Fixtures::verifyValid());
        $gate = $this->gate();

        $verified = $gate->evaluate(Fixtures::specOffer(), $this->signed(SpecExamples::PAYMENT_SIGNATURE));
        self::assertInstanceOf(Verified::class, $verified);
        $gate->abandon($verified);

        self::assertInstanceOf(Verified::class, $gate->evaluate(Fixtures::specOffer(), $this->signed(SpecExamples::PAYMENT_SIGNATURE)));
    }

    public function test_settlement_failure_returns402_with_the_payment_response_and_releases_the_lock(): void
    {
        $this->transport
            ->pushJson(Fixtures::verifyValid())
            ->pushJson(['success' => false, 'errorReason' => 'insufficient_funds', 'transaction' => '', 'network' => 'eip155:84532', 'payer' => SpecExamples::PAYER]);
        $gate = $this->gate();
        $verified = $gate->evaluate(Fixtures::specOffer(), $this->signed(SpecExamples::PAYMENT_SIGNATURE));
        self::assertInstanceOf(Verified::class, $verified);

        $result = $gate->settle($verified);

        self::assertInstanceOf(SettlementFailed::class, $result);
        self::assertSame('insufficient_funds', $result->reason);
        self::assertSame(402, $result->reply->status);
        self::assertSame(SpecExamples::PAYMENT_RESPONSE_FAILURE, $result->reply->headers['PAYMENT-RESPONSE']);
        self::assertSame('insufficient_funds', HeaderCodec::decode($result->reply->headers['PAYMENT-REQUIRED'])['data']['error']);
        self::assertFalse($this->lock->isLocked($verified->lockKey));
    }

    public function test_pending_settlement_is_retried_exactly_once(): void
    {
        $pending = ['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => Fixtures::TX_HASH, 'network' => 'eip155:84532'];
        $this->transport->pushJson(Fixtures::verifyValid())->pushJson($pending)->pushJson(Fixtures::settleSuccess());
        $gate = $this->gate();
        $verified = $gate->evaluate(Fixtures::specOffer(), $this->signed(SpecExamples::PAYMENT_SIGNATURE));
        self::assertInstanceOf(Verified::class, $verified);

        self::assertInstanceOf(Settled::class, $gate->settle($verified));
        self::assertCount(3, $this->transport->sent());
        self::assertSame($this->transport->sent()[1]->body, $this->transport->sent()[2]->body);
    }

    public function test_still_pending_after_the_retry_fails_but_keeps_the_lock(): void
    {
        $pending = ['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => Fixtures::TX_HASH, 'network' => 'eip155:84532'];
        $this->transport->pushJson(Fixtures::verifyValid())->pushJson($pending)->pushJson($pending);
        $gate = $this->gate();
        $verified = $gate->evaluate(Fixtures::specOffer(), $this->signed(SpecExamples::PAYMENT_SIGNATURE));
        self::assertInstanceOf(Verified::class, $verified);

        $result = $gate->settle($verified);

        self::assertInstanceOf(SettlementFailed::class, $result);
        self::assertSame('settlement_pending', $result->reason);
        self::assertSame(Fixtures::TX_HASH, $result->settlement?->transaction);
        self::assertCount(3, $this->transport->sent());
        self::assertTrue($this->lock->isLocked($verified->lockKey), 'the transfer may still confirm');
    }

    public function test_facilitator_outage_during_settle_is_a502_and_keeps_the_lock(): void
    {
        $this->transport->pushJson(Fixtures::verifyValid())->push(new HttpResponse(504, [], 'Gateway Timeout'));
        $gate = $this->gate();
        $verified = $gate->evaluate(Fixtures::specOffer(), $this->signed(SpecExamples::PAYMENT_SIGNATURE));
        self::assertInstanceOf(Verified::class, $verified);

        $result = $gate->settle($verified);

        self::assertInstanceOf(SettlementFailed::class, $result);
        self::assertSame(502, $result->reply->status);
        self::assertNull($result->settlement);
        self::assertTrue($this->lock->isLocked($verified->lockKey));
    }

    public function test_v1_compatibility_advertises_a_v1_body_and_settles_v1_payments(): void
    {
        $this->transport->pushJson(Fixtures::verifyValid())->pushJson(['success' => true, 'transaction' => Fixtures::TX_HASH, 'network' => 'base-sepolia', 'payer' => SpecExamples::PAYER]);
        $gate = $this->gate(acceptV1: true);

        $challenge = $gate->evaluate(Fixtures::specOffer(), new BasicRequest([]));
        self::assertInstanceOf(Challenge::class, $challenge);
        self::assertSame(1, $challenge->reply->body['x402Version']);
        self::assertSame('base-sepolia', $challenge->reply->body['accepts'][0]['network']);
        self::assertSame(2, HeaderCodec::decode($challenge->reply->headers['PAYMENT-REQUIRED'])['data']['x402Version']);

        $verified = $gate->evaluate(Fixtures::specOffer(), new BasicRequest(['X-PAYMENT' => SpecExamples::V1_X_PAYMENT]));
        self::assertInstanceOf(Verified::class, $verified);

        $verifyBody = json_decode((string) $this->transport->sent()[0]->body, true);
        self::assertSame(1, $verifyBody['x402Version']);
        self::assertSame('base-sepolia', $verifyBody['paymentRequirements']['network']);
        self::assertSame('10000', $verifyBody['paymentRequirements']['maxAmountRequired']);
        self::assertSame(SpecExamples::RESOURCE_URL, $verifyBody['paymentRequirements']['resource']);

        $settled = $gate->settle($verified);
        self::assertInstanceOf(Settled::class, $settled);
        self::assertArrayHasKey('X-PAYMENT-RESPONSE', $settled->headers);
        self::assertArrayNotHasKey('PAYMENT-RESPONSE', $settled->headers);
    }

    public function test_v1_payment_on_another_network_does_not_match(): void
    {
        $decision = $this->gate(acceptV1: true)->evaluate(Fixtures::specOffer(), new BasicRequest(['X-PAYMENT' => Fixtures::v1Header('base')]));

        self::assertInstanceOf(Rejected::class, $decision);
        self::assertSame('No matching payment requirements', $decision->reason);
        self::assertSame(1, $decision->reply->body['x402Version'], 'v1 clients get a v1 body');
    }

    public function test_payment_signature_header_wins_over_x_payment(): void
    {
        $this->transport->pushJson(Fixtures::verifyValid());

        $decision = $this->gate(acceptV1: true)->evaluate(Fixtures::specOffer(), new BasicRequest([
            'payment-signature' => SpecExamples::PAYMENT_SIGNATURE,
            'x-payment' => SpecExamples::V1_X_PAYMENT,
        ]));

        self::assertInstanceOf(Verified::class, $decision);
        self::assertSame(2, $decision->payment->version->value);
    }

    private function gate(bool $acceptV1 = false): PaymentGate
    {
        return new PaymentGate(new FacilitatorClient($this->transport, Fixtures::FACILITATOR_URL), $this->lock, $acceptV1);
    }

    private function signed(string $header): BasicRequest
    {
        return new BasicRequest(['Payment-Signature' => $header]);
    }

    private function lockKey(string $header): string
    {
        return 'x402:payment:'.PaymentPayload::fromHeader($header)->fingerprint();
    }
}
