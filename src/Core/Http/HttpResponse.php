<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Http;

/**
 * A facilitator response as seen by the transport. Header names are lower-cased.
 */
final readonly class HttpResponse
{
    /** @var array<string, string> */
    public array $headers;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        array $headers = [],
        public string $body = '',
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** 307/308 keep the method and body; other redirects would turn a POST into a GET. */
    public function isMethodPreservingRedirect(): bool
    {
        return $this->status === 307 || $this->status === 308;
    }
}
