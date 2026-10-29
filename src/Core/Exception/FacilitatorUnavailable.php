<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Exception;

use RuntimeException;
use Throwable;

/**
 * The facilitator could not be reached or answered with something that is not
 * a valid x402 verify/settle response (timeout, connection error, 5xx, HTML,
 * malformed JSON, cross-host redirect, ...).
 *
 * The message is meant for server-side logs; it is never sent to the client.
 */
final class FacilitatorUnavailable extends RuntimeException implements X402Exception
{
    public static function because(string $operation, string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('Facilitator %s failed: %s', $operation, $reason), 0, $previous);
    }
}
