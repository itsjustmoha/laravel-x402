<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Core\Http;

use Itsjustmoha\X402\Core\Exception\TransportException;
use Itsjustmoha\X402\Core\Facilitator\FacilitatorClient;
use Itsjustmoha\X402\Core\Gate\BasicRequest;
use Itsjustmoha\X402\Core\Gate\PaymentGate;
use Itsjustmoha\X402\Core\Gate\Settled;
use Itsjustmoha\X402\Core\Gate\Verified;
use Itsjustmoha\X402\Core\Http\CurlHttpTransport;
use Itsjustmoha\X402\Core\Http\HttpRequest;
use Itsjustmoha\X402\Core\Testing\InMemoryPaymentLock;
use Itsjustmoha\X402\Tests\Core\Support\Fixtures;
use Itsjustmoha\X402\Tests\Core\Support\SpecExamples;
use PHPUnit\Framework\TestCase;

/**
 * Real HTTP round trips against a local `php -S` stand-in facilitator.
 */
final class CurlHttpTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        if (! extension_loaded('curl') || ! function_exists('proc_open')) {
            return;
        }

        $port = self::freePort();
        $command = [PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/fixtures/facilitator-router.php'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        if (! is_resource($process)) {
            return;
        }

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                self::$server = $process;
                self::$baseUrl = 'http://127.0.0.1:'.$port;

                return;
            }
            usleep(50_000);
        }

        proc_terminate($process);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$baseUrl === '') {
            self::markTestSkipped('ext-curl, proc_open or a local php -S server is not available.');
        }
    }

    public function test_sends_method_headers_and_body(): void
    {
        $response = (new CurlHttpTransport)->send(new HttpRequest('POST', self::$baseUrl.'/echo', [
            'Content-Type' => 'application/json',
            'X-Test' => 'hello',
        ], '{"a":1}'));

        self::assertSame(200, $response->status);
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame(['method' => 'POST', 'contentType' => 'application/json', 'testHeader' => 'hello', 'body' => '{"a":1}'], json_decode($response->body, true));
    }

    public function test_does_not_follow_redirects(): void
    {
        $response = (new CurlHttpTransport)->send(new HttpRequest('GET', self::$baseUrl.'/redirect'));

        self::assertSame(308, $response->status);
        self::assertSame('/echo', $response->header('location'));
    }

    public function test_timeout_throws_transport_exception(): void
    {
        $this->expectException(TransportException::class);

        (new CurlHttpTransport(0.3, 0.3))->send(new HttpRequest('GET', self::$baseUrl.'/slow'));
    }

    public function test_connection_refused_throws_transport_exception(): void
    {
        $this->expectException(TransportException::class);

        (new CurlHttpTransport(1.0, 1.0))->send(new HttpRequest('GET', 'http://127.0.0.1:'.self::freePort().'/'));
    }

    public function test_full_payment_flow_over_real_http(): void
    {
        $client = new FacilitatorClient(new CurlHttpTransport(5.0, 1.0), self::$baseUrl.'/facilitator');
        $gate = new PaymentGate($client, new InMemoryPaymentLock);

        $verified = $gate->evaluate(Fixtures::specOffer(), new BasicRequest(['PAYMENT-SIGNATURE' => SpecExamples::PAYMENT_SIGNATURE]));
        self::assertInstanceOf(Verified::class, $verified);
        self::assertSame(SpecExamples::PAYER, $verified->payer());

        $settled = $gate->settle($verified);
        self::assertInstanceOf(Settled::class, $settled);
        self::assertSame('0x'.str_repeat('ab', 32), $settled->settlement->transaction);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
