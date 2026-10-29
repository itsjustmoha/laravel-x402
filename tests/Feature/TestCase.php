<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Itsjustmoha\X402\Core\Codec\HeaderCodec;
use Itsjustmoha\X402\Laravel\X402ServiceProvider;
use Itsjustmoha\X402\Tests\Core\Support\Fixtures;
use Itsjustmoha\X402\Tests\Core\Support\SpecExamples;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends Orchestra
{
    public const string FACILITATOR = 'https://facilitator.test';

    public static int $handlerCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$handlerCalls = 0;
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [X402ServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('x402.pay_to', SpecExamples::PAY_TO);
        $app['config']->set('x402.facilitator.url', self::FACILITATOR);
        $app['config']->set('x402.logging.enabled', false);
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/paid', static function () {
            self::$handlerCalls++;

            return response()->json(['data' => 'premium']);
        })->paid('$0.01', ['description' => 'Premium data']);

        $router->get('/paid-throws', static function (): never {
            self::$handlerCalls++;

            throw new RuntimeException('handler exploded');
        })->paid('$0.01');

        $router->get('/paid-missing', static function (): never {
            self::$handlerCalls++;

            abort(404);
        })->paid('$0.01');

        $router->post('/param', static function () {
            self::$handlerCalls++;

            return ['ok' => true];
        })->middleware('x402:0.05');

        $router->get('/weather', static fn () => ['city' => 'Berlin', 'temperature' => 12])->paid('$0.001', [
            'description' => 'Current weather',
            'discovery' => [
                'input' => ['city' => 'Berlin'],
                'input_schema' => ['properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
                'output' => ['city' => 'Berlin', 'temperature' => 12],
            ],
        ]);

        $router->get('/users/{user}', static fn (string $user) => ['user' => $user])->paid('$0.01', ['discovery' => []]);

        $router->get('/mainnet', static fn () => ['ok' => true])->paid('$0.01', ['network' => 'eip155:8453']);
    }

    /**
     * Requests the 402 challenge for $uri and signs a fake payment for exactly
     * the advertised terms, like an x402 client would.
     */
    protected function paymentFor(string $uri, string $method = 'GET', string $nonce = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): string
    {
        $challenge = $this->call($method, $uri);
        $challenge->assertStatus(402);

        $required = HeaderCodec::decode((string) $challenge->headers->get('PAYMENT-REQUIRED'))['data'];

        return HeaderCodec::encode([
            'x402Version' => 2,
            'resource' => $required['resource'],
            'accepted' => $required['accepts'][0],
            'payload' => Fixtures::schemePayload($nonce),
        ]);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    protected static function decodeHeader(TestResponse $response, string $name): array
    {
        $value = $response->headers->get($name);
        self::assertIsString($value, $name.' header is missing');

        return HeaderCodec::decode($value)['data'];
    }
}
