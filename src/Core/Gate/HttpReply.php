<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Gate;

/**
 * A framework-neutral JSON response the gate wants to send to the client.
 */
final readonly class HttpReply
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body  JSON object body
     */
    public function __construct(
        public int $status,
        public array $headers,
        public array $body,
    ) {}
}
