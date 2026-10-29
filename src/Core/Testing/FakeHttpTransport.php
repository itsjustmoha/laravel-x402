<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Testing;

use Closure;
use Itsjustmoha\X402\Core\Exception\TransportException;
use Itsjustmoha\X402\Core\Http\HttpRequest;
use Itsjustmoha\X402\Core\Http\HttpResponse;
use Itsjustmoha\X402\Core\Http\HttpTransport;
use LogicException;

/**
 * In-memory HttpTransport for tests: queue responses (or exceptions, or
 * closures receiving the request) and inspect the recorded requests.
 */
final class FakeHttpTransport implements HttpTransport
{
    /** @var list<HttpResponse|TransportException|Closure(HttpRequest): HttpResponse> */
    private array $queue = [];

    /** @var list<HttpRequest> */
    private array $sent = [];

    /**
     * @param  HttpResponse|TransportException|Closure(HttpRequest): HttpResponse  $response
     */
    public function push(HttpResponse|TransportException|Closure $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function pushJson(array $body, int $status = 200, array $headers = []): self
    {
        return $this->push(new HttpResponse($status, $headers + ['Content-Type' => 'application/json'], (string) json_encode($body)));
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->sent[] = $request;
        $next = array_shift($this->queue) ?? throw new LogicException(sprintf('No fake response queued for %s %s.', $request->method, $request->url));

        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next instanceof Closure ? $next($request) : $next;
    }

    /**
     * @return list<HttpRequest>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
