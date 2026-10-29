<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Facilitator;

use Itsjustmoha\X402\Core\Http\HttpRequest;

/**
 * Supplies authentication headers for facilitators that need them (for
 * example Coinbase CDP, which expects a short-lived JWT per request that is
 * bound to the HTTP method and URL).
 *
 * Called once per outgoing request, so implementations can mint fresh tokens.
 * These are API credentials for the facilitator, never wallet keys.
 */
interface AuthHeadersProvider
{
    /**
     * @param  'verify'|'settle'|'supported'  $operation
     * @return array<string, string>
     */
    public function headersFor(string $operation, HttpRequest $request): array;
}
