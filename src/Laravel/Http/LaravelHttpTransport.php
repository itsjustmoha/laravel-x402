<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Laravel\Http;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Itsjustmoha\X402\Core\Exception\TransportException;
use Itsjustmoha\X402\Core\Http\HttpRequest;
use Itsjustmoha\X402\Core\Http\HttpResponse;
use Itsjustmoha\X402\Core\Http\HttpTransport;

/**
 * HttpTransport on top of Laravel's HTTP client, so Http::fake(),
 * Http::preventStrayRequests() and global client middleware apply.
 */
final readonly class LaravelHttpTransport implements HttpTransport
{
    public function __construct(
        private float $timeoutSeconds,
        private float $connectTimeoutSeconds,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $pending = Http::timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->withoutRedirecting()
            ->withHeaders($request->headers);

        if ($request->body !== null) {
            $pending = $pending->withBody($request->body, $request->headers['Content-Type'] ?? 'application/json');
        }

        try {
            $response = $pending->send($request->method, $request->url);
        } catch (HttpClientException|TransferException $exception) {
            throw new TransportException(sprintf('%s %s failed: %s', $request->method, $request->url, $exception->getMessage()), 0, $exception);
        }

        $headers = [];
        foreach ($response->headers() as $name => $values) {
            $headers[(string) $name] = is_array($values) ? implode(', ', array_filter($values, is_string(...))) : (string) $values;
        }

        return new HttpResponse($response->status(), $headers, $response->body());
    }
}
