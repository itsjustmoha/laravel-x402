<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;
use Itsjustmoha\X402\Laravel\Events\PaymentFailed;
use Itsjustmoha\X402\Laravel\Events\PaymentSettled;
use Itsjustmoha\X402\Laravel\Events\PaymentVerified;
use Itsjustmoha\X402\Tests\Core\Support\Fixtures;
use Itsjustmoha\X402\Tests\Core\Support\SpecExamples;
use Itsjustmoha\X402\Tests\Feature\Fixtures\StaticAuthHeaders;
use RuntimeException;

final class RequirePaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_request_without_payment_gets_a402_challenge_with_header_and_body(): void
    {
        Http::fake();

        $response = $this->getJson('/paid');

        $response->assertStatus(402);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $required = self::decodeHeader($response, 'PAYMENT-REQUIRED');
        self::assertSame(2, $required['x402Version']);
        self::assertSame('Payment required', $required['error']);
        self::assertSame(['url' => 'http://localhost/paid', 'description' => 'Premium data', 'mimeType' => 'application/json'], $required['resource']);
        self::assertSame([
            'scheme' => 'exact',
            'network' => 'eip155:84532',
            'amount' => '10000',
            'asset' => SpecExamples::BASE_SEPOLIA_USDC,
            'payTo' => SpecExamples::PAY_TO,
            'maxTimeoutSeconds' => 60,
            'extra' => ['name' => 'USDC', 'version' => '2'],
        ], $required['accepts'][0]);
        $response->assertExactJson($required);

        self::assertSame(0, self::$handlerCalls);
        Http::assertNothingSent();
    }

    public function test_valid_payment_is_verified_handled_and_settled(): void
    {
        Event::fake([PaymentVerified::class, PaymentSettled::class, PaymentFailed::class]);
        Http::fake([
            self::FACILITATOR.'/verify' => Http::response(Fixtures::verifyValid()),
            self::FACILITATOR.'/settle' => Http::response(Fixtures::settleSuccess()),
        ]);

        $response = $this->withHeader('PAYMENT-SIGNATURE', $this->paymentFor('/paid'))->getJson('/paid');

        $response->assertOk()->assertExactJson(['data' => 'premium']);
        self::assertSame(1, self::$handlerCalls);
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        $settlement = self::decodeHeader($response, 'PAYMENT-RESPONSE');
        self::assertTrue($settlement['success']);
        self::assertSame(Fixtures::TX_HASH, $settlement['transaction']);
        self::assertSame('eip155:84532', $settlement['network']);

        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => $request->url() === self::FACILITATOR.'/verify'
            && $request->method() === 'POST'
            && $request['x402Version'] === 2
            && $request['paymentRequirements']['amount'] === '10000'
            && $request['paymentRequirements']['payTo'] === SpecExamples::PAY_TO
            && $request['paymentPayload']['payload']['authorization']['from'] === SpecExamples::PAYER);
        Http::assertSent(static fn (Request $request): bool => $request->url() === self::FACILITATOR.'/settle');

        Event::assertDispatched(PaymentVerified::class, static fn (PaymentVerified $event): bool => $event->payer === SpecExamples::PAYER && $event->path === 'paid');
        Event::assertDispatched(PaymentSettled::class, static fn (PaymentSettled $event): bool => $event->settlement->transaction === Fixtures::TX_HASH);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_a_failing_settled_listener_does_not_cost_the_payer_the_response(): void
    {
        Event::listen(PaymentSettled::class, static function (): never {
            throw new RuntimeException('listener exploded');
        });
        Http::fake([
            self::FACILITATOR.'/verify' => Http::response(Fixtures::verifyValid()),
            self::FACILITATOR.'/settle' => Http::response(Fixtures::settleSuccess()),
        ]);

        $response = $this->withHeader('PAYMENT-SIGNATURE', $this->paymentFor('/paid'))->getJson('/paid');

        $response->assertOk()->assertExactJson(['data' => 'premium']);
        self::assertTrue(self::decodeHeader($response, 'PAYMENT-RESPONSE')['success']);
    }

    public function test_invalid_payment_returns402_with_reason_and_never_runs_the_handler(): void
    {
        Event::fake([PaymentFailed::class]);
        Http::fake([
            self::FACILITATOR.'/verify' => Http::response(['isValid' => false, 'invalidReason' => 'insufficient_funds', 'payer' => SpecExamples::PAYER]),
        ]);

        $response = $this->withHeader('PAYMENT-SIGNATURE', $this->paymentFor('/paid'))->getJson('/paid');

        $response->assertStatus(402);
        self::assertSame('insufficient_funds', self::decodeHeader($response, 'PAYMENT-REQUIRED')['error']);
        $response->assertJsonPath('error', 'insufficient_funds');
        self::assertNull($response->headers->get('PAYMENT-RESPONSE'));
        self::assertSame(0, self::$handlerCalls);
        Http::assertSentCount(1);
        Event::assertDispatched(PaymentFailed::class, static fn (PaymentFailed $event): bool => $event->stage === PaymentFailed::STAGE_VERIFY
            && $event->reason === 'insufficient_funds'
            && $event->status === 402);
    }

    public function test_malformed_payment_header_is_rejected_without_calling_the_facilitator(): void
    {
        Http::fake();

        $response = $this->withHeader('PAYMENT-SIGNATURE', '%%% not base64 %%%')->getJson('/paid');

        $response->assertStatus(402)->assertJsonPath('error', 'invalid_payload');
        Http::assertNothingSent();
    }

    public function test_handler_exception_means_no_settlement(): void
    {
        Http::fake([self::FACILITATOR.'/verify' => Http::response(Fixtures::verifyValid())]);
        $payment = $this->paymentFor('/paid-throws');

        $response = $this->withHeader('PAYMENT-SIGNATURE', $payment)->getJson('/paid-throws');

        $response->assertStatus(500);
        self::assertNull($response->headers->get('PAYMENT-RESPONSE'));
        self::assertSame(1, self::$handlerCalls);
        Http::assertSentCount(1);
        Http::assertNotSent(static fn (Request $request): bool => str_ends_with($request->url(), '/settle'));

        // The lock was released, so the same signed payment can be retried.
        $this->withHeader('PAYMENT-SIGNATURE', $payment)->getJson('/paid-throws')->assertStatus(500);
        Http::assertSentCount(2);
    }

    public function test_handler_exception_is_rethrown_without_exception_handling(): void
    {
        Http::fake([self::FACILITATOR.'/verify' => Http::response(Fixtures::verifyValid())]);
        $payment = $this->paymentFor('/paid-throws');
        $this->withoutExceptionHandling();

        try {
            $this->withHeader('PAYMENT-SIGNATURE', $payment)->getJson('/paid-throws');
            self::fail('The handler exception should propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('handler exploded', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_non2xx_response_is_not_settled(): void
    {
        Http::fake([self::FACILITATOR.'/verify' => Http::response(Fixtures::verifyValid())]);

        $response = $this->withHeader('PAYMENT-SIGNATURE', $this->paymentFor('/paid-missing'))->getJson('/paid-missing');

        $response->assertNotFound();
        self::assertNull($response->headers->get('PAYMENT-RESPONSE'));
        Http::assertSentCount(1);
    }

    public function test_facilitator_connection_failure_during_verify_returns502(): void
    {
        Event::fake([PaymentFailed::class]);
        Http::fake([self::FACILITATOR.'/*' => Http::failedConnection()]);

        $response = $this->withHeader('PAYMENT-SIGNATURE', $this->paymentFor('/paid'))->getJson('/paid');

        $response->assertStatus(502)->assertExactJson(['error' => 'facilitator_unavailable']);
        self::assertSame(0, self::$handlerCalls);
        Event::assertDispatched(PaymentFailed::class, static fn (PaymentFailed $event): bool => $event->status === 502);
    }

    public function test_facilitator_server_error_during_verify_returns502(): void
    {
        Http::fake([self::FACILITATOR.'/verify' => Http::response('<html>Service Unavailable</html>', 503)]);

        $response = $this->withHeader('PAYMENT-SIGNATURE', $this->paymentFor('/paid'))->getJson('/paid');

        $response->assertStatus(502)->assertJsonPath('error', 'facilitator_unavailable');
        self::assertSame(0, self::$handlerCalls);
    }

    public function test_settlement_failure_returns402_and_discards_the_handler_response(): void
    {
        Event::fake([PaymentFailed::class, PaymentSettled::class]);
        Http::fake([
            self::FACILITATOR.'/verify' => Http::response(Fixtures::verifyValid()),
            self::FACILITATOR.'/settle' => Http::response(['success' => false, 'errorReason' => 'insufficient_funds', 'transaction' => '', 'network' => 'eip155:84532']),
        ]);

        $response = $this->withHeader('PAYMENT-SIGNATURE', $this->paymentFor('/paid'))->getJson('/paid');

        $response->assertStatus(402);
        self::assertStringNotContainsString('premium', (string) $response->getContent());
        self::assertFalse(self::decodeHeader($response, 'PAYMENT-RESPONSE')['success']);
        self::assertSame('insufficient_funds', self::decodeHeader($response, 'PAYMENT-RESPONSE')['errorReason']);
        self::assertSame(1, self::$handlerCalls);
        Event::assertDispatched(PaymentFailed::class, static fn (PaymentFailed $event): bool => $event->stage === PaymentFailed::STAGE_SETTLE);
        Event::assertNotDispatched(PaymentSettled::class);
    }

    public function test_facilitator_outage_during_settle_returns502(): void
    {
        Http::fake([
            self::FACILITATOR.'/verify' => Http::response(Fixtures::verifyValid()),
            self::FACILITATOR.'/settle' => Http::failedConnection(),
        ]);

        $response = $this->withHeader('PAYMENT-SIGNATURE', $this->paymentFor('/paid'))->getJson('/paid');

        $response->assertStatus(502)->assertExactJson(['error' => 'facilitator_unavailable']);
        self::assertSame(1, self::$handlerCalls);
    }

    public function test_the_same_signed_payment_cannot_unlock_twice(): void
    {
        Http::fake([
            self::FACILITATOR.'/verify' => Http::response(Fixtures::verifyValid()),
            self::FACILITATOR.'/settle' => Http::response(Fixtures::settleSuccess()),
        ]);
        $payment = $this->paymentFor('/paid');

        $this->withHeader('PAYMENT-SIGNATURE', $payment)->getJson('/paid')->assertOk();
        $this->withHeader('PAYMENT-SIGNATURE', $payment)->getJson('/paid')
            ->assertStatus(402)
            ->assertJsonPath('error', 'duplicate_payment');

        self::assertSame(1, self::$handlerCalls);
        Http::assertSentCount(2);
    }

    public function test_middleware_parameter_sets_the_price(): void
    {
        Http::fake();

        $response = $this->postJson('/param');

        $response->assertStatus(402);
        self::assertSame('50000', self::decodeHeader($response, 'PAYMENT-REQUIRED')['accepts'][0]['amount']);
    }

    public function test_bazaar_discovery_metadata_is_advertised(): void
    {
        Http::fake();
        config(['x402.service' => ['name' => 'Weather API', 'tags' => ['weather'], 'icon_url' => 'https://localhost/icon.png']]);

        $required = self::decodeHeader($this->getJson('/weather'), 'PAYMENT-REQUIRED');

        self::assertSame('1000', $required['accepts'][0]['amount']);
        self::assertSame('Weather API', $required['resource']['serviceName']);
        self::assertSame(['weather'], $required['resource']['tags']);
        self::assertArrayNotHasKey('iconUrl', $required['resource'], 'loopback icon URLs are dropped');
        self::assertSame(['type' => 'http', 'method' => 'GET', 'queryParams' => ['city' => 'Berlin']], $required['extensions']['bazaar']['info']['input']);
        self::assertSame(['type' => 'json', 'example' => ['city' => 'Berlin', 'temperature' => 12]], $required['extensions']['bazaar']['info']['output']);
        self::assertSame(['GET'], $required['extensions']['bazaar']['schema']['properties']['input']['properties']['method']['enum']);

        $dynamic = self::decodeHeader($this->getJson('/users/42'), 'PAYMENT-REQUIRED');
        self::assertSame('/users/:user', $dynamic['extensions']['bazaar']['routeTemplate']);
        self::assertSame(['user' => '42'], $dynamic['extensions']['bazaar']['info']['input']['pathParams']);
    }

    public function test_v1_clients_can_pay_when_compatibility_is_enabled(): void
    {
        config(['x402.v1_compatibility' => true]);
        Http::fake([
            self::FACILITATOR.'/verify' => Http::response(Fixtures::verifyValid()),
            self::FACILITATOR.'/settle' => Http::response(['success' => true, 'transaction' => Fixtures::TX_HASH, 'network' => 'base-sepolia', 'payer' => SpecExamples::PAYER]),
        ]);

        $challenge = $this->getJson('/paid');
        $challenge->assertStatus(402)->assertJsonPath('x402Version', 1)->assertJsonPath('accepts.0.network', 'base-sepolia');

        $response = $this->withHeader('X-PAYMENT', Fixtures::v1Header())->getJson('/paid');

        $response->assertOk();
        self::assertTrue(self::decodeHeader($response, 'X-PAYMENT-RESPONSE')['success']);
        Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/verify')
            && $request['x402Version'] === 1
            && $request['paymentRequirements']['maxAmountRequired'] === '10000'
            && $request['paymentRequirements']['network'] === 'base-sepolia');
    }

    public function test_v1_payments_are_rejected_by_default(): void
    {
        Http::fake();

        $this->withHeader('X-PAYMENT', Fixtures::v1Header())->getJson('/paid')
            ->assertStatus(402)
            ->assertJsonPath('error', 'invalid_x402_version');

        Http::assertNothingSent();
    }

    public function test_auth_headers_provider_is_used_for_facilitator_calls(): void
    {
        config(['x402.facilitator.auth' => StaticAuthHeaders::class]);
        Http::fake([
            self::FACILITATOR.'/verify' => Http::response(Fixtures::verifyValid()),
            self::FACILITATOR.'/settle' => Http::response(Fixtures::settleSuccess()),
        ]);

        $this->withHeader('PAYMENT-SIGNATURE', $this->paymentFor('/paid'))->getJson('/paid')->assertOk();

        Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/verify') && $request->hasHeader('Authorization', 'Bearer test-verify'));
        Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/settle') && $request->hasHeader('Authorization', 'Bearer test-settle'));
    }

    public function test_missing_pay_to_is_a_configuration_error(): void
    {
        config(['x402.pay_to' => null]);
        $this->withoutExceptionHandling();

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('X402_PAY_TO');

        $this->getJson('/paid');
    }

    public function test_mainnet_network_with_the_testnet_facilitator_is_a_configuration_error(): void
    {
        config(['x402.facilitator.url' => 'https://x402.org/facilitator']);
        $this->withoutExceptionHandling();

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('testnet-only');

        $this->getJson('/mainnet');
    }

    public function test_mainnet_network_with_a_production_facilitator_is_allowed(): void
    {
        Http::fake();

        $required = self::decodeHeader($this->getJson('/mainnet'), 'PAYMENT-REQUIRED');

        self::assertSame('eip155:8453', $required['accepts'][0]['network']);
        self::assertSame('0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', $required['accepts'][0]['asset']);
        self::assertSame(['name' => 'USD Coin', 'version' => '2'], $required['accepts'][0]['extra']);
    }
}
