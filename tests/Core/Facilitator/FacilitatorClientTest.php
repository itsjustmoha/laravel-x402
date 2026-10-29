<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Core\Facilitator;

use Itsjustmoha\X402\Core\Exception\FacilitatorUnavailable;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;
use Itsjustmoha\X402\Core\Exception\TransportException;
use Itsjustmoha\X402\Core\Facilitator\AuthHeadersProvider;
use Itsjustmoha\X402\Core\Facilitator\FacilitatorClient;
use Itsjustmoha\X402\Core\Http\HttpRequest;
use Itsjustmoha\X402\Core\Http\HttpResponse;
use Itsjustmoha\X402\Core\Protocol\PaymentPayload;
use Itsjustmoha\X402\Core\Testing\FakeHttpTransport;
use Itsjustmoha\X402\Tests\Core\Support\Fixtures;
use Itsjustmoha\X402\Tests\Core\Support\SpecExamples;
use PHPUnit\Framework\TestCase;

final class FacilitatorClientTest extends TestCase
{
    private FakeHttpTransport $transport;

    private FacilitatorClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeHttpTransport;
        $this->client = new FacilitatorClient($this->transport, Fixtures::FACILITATOR_URL.'/');
    }

    public function test_verify_sends_the_specified_request_shape(): void
    {
        $this->transport->pushJson(Fixtures::verifyValid());
        $payment = PaymentPayload::fromHeader(SpecExamples::PAYMENT_SIGNATURE);

        $result = $this->client->verify($payment, Fixtures::specRequirements()->toArray());

        self::assertTrue($result->isValid);
        self::assertSame(SpecExamples::PAYER, $result->payer);

        $request = $this->transport->sent()[0];
        self::assertSame('POST', $request->method);
        self::assertSame('https://facilitator.test/verify', $request->url);
        self::assertSame('application/json', $request->headers['Content-Type']);

        $body = json_decode((string) $request->body, true);
        self::assertSame(2, $body['x402Version']);
        self::assertSame(json_decode($payment->json, true), $body['paymentPayload']);
        self::assertSame(Fixtures::specRequirements()->toArray(), $body['paymentRequirements']);
        self::assertStringContainsString('"paymentPayload":'.$payment->json, (string) $request->body);
    }

    public function test_verify_returns_invalid_results(): void
    {
        $this->transport->pushJson(['isValid' => false, 'invalidReason' => 'insufficient_funds', 'payer' => SpecExamples::PAYER]);

        $result = $this->client->verify(PaymentPayload::fromHeader(SpecExamples::PAYMENT_SIGNATURE), []);

        self::assertFalse($result->isValid);
        self::assertSame('insufficient_funds', $result->invalidReason);
    }

    public function test_error_status_with_a_verify_body_is_an_invalid_result_never_a_valid_one(): void
    {
        $this->transport
            ->pushJson(['isValid' => false, 'invalidReason' => 'invalid_exact_evm_payload_signature'], 400)
            ->pushJson(['isValid' => true], 500);
        $payment = PaymentPayload::fromHeader(SpecExamples::PAYMENT_SIGNATURE);

        self::assertSame('invalid_exact_evm_payload_signature', $this->client->verify($payment, [])->invalidReason);

        $forced = $this->client->verify($payment, []);
        self::assertFalse($forced->isValid);
        self::assertSame('unexpected_verify_error', $forced->invalidReason);
    }

    public function test_unusable_facilitator_answers_throw(): void
    {
        $payment = PaymentPayload::fromHeader(SpecExamples::PAYMENT_SIGNATURE);
        $answers = [
            'html 502' => new HttpResponse(502, ['Content-Type' => 'text/html'], '<html>Bad Gateway</html>'),
            '401 without body' => new HttpResponse(401, [], ''),
            '200 invalid json' => new HttpResponse(200, [], '{"isValid":'),
            '200 wrong shape' => new HttpResponse(200, [], '{"valid":true}'),
            'connection refused' => new TransportException('Connection refused'),
            'cross-origin redirect' => new HttpResponse(307, ['Location' => 'https://evil.test/verify']),
            'method-changing redirect' => new HttpResponse(302, ['Location' => '/verify2']),
        ];

        foreach ($answers as $label => $answer) {
            $this->transport->push($answer);

            try {
                $this->client->verify($payment, []);
                self::fail($label.' should have thrown');
            } catch (FacilitatorUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_follows_same_origin_method_preserving_redirects(): void
    {
        $this->transport
            ->push(new HttpResponse(308, ['Location' => '/v2/verify']))
            ->pushJson(Fixtures::verifyValid());

        self::assertTrue($this->client->verify(PaymentPayload::fromHeader(SpecExamples::PAYMENT_SIGNATURE), [])->isValid);

        [$first, $second] = $this->transport->sent();
        self::assertSame('https://facilitator.test/verify', $first->url);
        self::assertSame('https://facilitator.test/v2/verify', $second->url);
        self::assertSame('POST', $second->method);
        self::assertSame($first->body, $second->body);
    }

    public function test_settle_parses_success_and_failure(): void
    {
        $payment = PaymentPayload::fromHeader(SpecExamples::PAYMENT_SIGNATURE);
        $this->transport
            ->pushJson(Fixtures::settleSuccess())
            ->pushJson(['success' => false, 'errorReason' => 'insufficient_funds', 'transaction' => '', 'network' => 'eip155:84532'], 400)
            ->pushJson(['success' => false], 422)
            ->pushJson(Fixtures::settleSuccess(), 500);

        $success = $this->client->settle($payment, []);
        self::assertTrue($success->success);
        self::assertSame(Fixtures::TX_HASH, $success->transaction);
        self::assertSame('https://facilitator.test/settle', $this->transport->sent()[0]->url);

        $failure = $this->client->settle($payment, []);
        self::assertFalse($failure->success);
        self::assertSame('insufficient_funds', $failure->errorReason);

        $minimal = $this->client->settle($payment, []);
        self::assertSame('unexpected_settle_error', $minimal->errorReason);
        self::assertSame('eip155:84532', $minimal->network);

        $forced = $this->client->settle($payment, []);
        self::assertFalse($forced->success);
        self::assertSame('unexpected_settle_error', $forced->errorReason);
    }

    public function test_successful_settle_must_carry_transaction_and_network(): void
    {
        $this->transport->pushJson(['success' => true]);

        $this->expectException(FacilitatorUnavailable::class);

        $this->client->settle(PaymentPayload::fromHeader(SpecExamples::PAYMENT_SIGNATURE), []);
    }

    public function test_supported_parses_kinds(): void
    {
        $this->transport->pushJson([
            'kinds' => [
                ['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:84532'],
                ['x402Version' => 1, 'scheme' => 'exact', 'network' => 'base-sepolia'],
                ['garbage' => true],
            ],
            'extensions' => ['bazaar', 7],
            'signers' => ['eip155:*' => ['0x1234567890abcdef1234567890abcdef12345678']],
        ]);

        $supported = $this->client->supported();

        self::assertSame('GET', $this->transport->sent()[0]->method);
        self::assertSame('https://facilitator.test/supported', $this->transport->sent()[0]->url);
        self::assertCount(2, $supported->kinds);
        self::assertSame(['bazaar'], $supported->extensions);
        self::assertTrue($supported->supports(2, 'exact', 'eip155:84532'));
        self::assertTrue($supported->supports(1, 'exact', 'base-sepolia'));
        self::assertFalse($supported->supports(2, 'exact', 'eip155:8453'));
    }

    public function test_auth_headers_are_requested_per_operation_and_request(): void
    {
        $auth = new class implements AuthHeadersProvider
        {
            /** @var list<string> */
            public array $calls = [];

            public function headersFor(string $operation, HttpRequest $request): array
            {
                $this->calls[] = $operation.' '.$request->method.' '.$request->url;

                return ['Authorization' => 'Bearer token-for-'.$operation];
            }
        };
        $client = new FacilitatorClient($this->transport, Fixtures::FACILITATOR_URL, $auth);
        $this->transport->pushJson(Fixtures::verifyValid())->pushJson(Fixtures::settleSuccess());
        $payment = PaymentPayload::fromHeader(SpecExamples::PAYMENT_SIGNATURE);

        $client->verify($payment, []);
        $client->settle($payment, []);

        self::assertSame(['verify POST https://facilitator.test/verify', 'settle POST https://facilitator.test/settle'], $auth->calls);
        self::assertSame('Bearer token-for-verify', $this->transport->sent()[0]->headers['Authorization']);
        self::assertSame('Bearer token-for-settle', $this->transport->sent()[1]->headers['Authorization']);
    }

    public function test_rejects_invalid_base_urls(): void
    {
        foreach (['', 'facilitator.test', 'ftp://facilitator.test', '/relative'] as $url) {
            try {
                new FacilitatorClient($this->transport, $url);
                self::fail($url.' should have been rejected');
            } catch (InvalidConfiguration) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
