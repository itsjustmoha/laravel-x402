<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Gate;

use Itsjustmoha\X402\Core\Facilitator\SettlementResponse;

/**
 * Settlement failed after the handler ran. Discard the handler's response and
 * send $reply instead (402 with the failed SettlementResponse, or 502 when the
 * facilitator could not be reached and the outcome is unknown).
 */
final readonly class SettlementFailed
{
    public function __construct(
        public string $reason,
        public HttpReply $reply,
        public ?SettlementResponse $settlement,
    ) {}
}
