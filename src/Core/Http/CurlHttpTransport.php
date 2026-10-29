<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Http;

use Itsjustmoha\X402\Core\Exception\TransportException;

/**
 * Dependency-free HttpTransport based on ext-curl, for use outside Laravel.
 */
final readonly class CurlHttpTransport implements HttpTransport
{
    public function __construct(
        private float $timeoutSeconds = 30.0,
        private float $connectTimeoutSeconds = 5.0,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $method = $request->method;
        $url = $request->url;

        if ($method === '' || $url === '') {
            throw new TransportException('Cannot send a request without a method and URL.');
        }

        $handle = curl_init();

        if ($handle === false) {
            throw new TransportException('Could not initialise a curl handle.');
        }

        $responseHeaders = [];

        // An empty "Expect:" stops curl from waiting for "100 Continue" on larger bodies.
        $headerLines = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeoutSeconds * 1000),
            CURLOPT_HEADERFUNCTION => static function (mixed $curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];

        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);

        if (! is_string($body)) {
            throw new TransportException(sprintf('%s %s failed: %s', $request->method, $request->url, curl_error($handle)));
        }

        return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseHeaders, $body);
    }
}
