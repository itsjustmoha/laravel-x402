<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Http;

/**
 * An outgoing request to the facilitator.
 */
final readonly class HttpRequest
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public ?string $body = null,
    ) {}

    public function withUrl(string $url): self
    {
        return new self($this->method, $url, $this->headers, $this->body);
    }
}
