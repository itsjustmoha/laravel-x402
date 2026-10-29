<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Http;

use Itsjustmoha\X402\Core\Exception\TransportException;

/**
 * Minimal HTTP client abstraction used to talk to the facilitator.
 *
 * Implementations must not follow redirects (the FacilitatorClient decides
 * which redirects are safe) and must throw TransportException when no HTTP
 * response was received. Any HTTP status, including 4xx/5xx, is a response.
 */
interface HttpTransport
{
    /**
     * @throws TransportException
     */
    public function send(HttpRequest $request): HttpResponse;
}
