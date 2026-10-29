<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Gate;

use Itsjustmoha\X402\Core\Protocol\PaymentRequirements;

/**
 * A payment was presented but must not unlock the resource. The reply is a 402
 * (bad/insufficient payment) or a 502 (facilitator unavailable).
 */
final readonly class Rejected
{
    public function __construct(
        public string $reason,
        public HttpReply $reply,
        public ?PaymentRequirements $requirements = null,
        public ?string $payer = null,
    ) {}

    public function isFacilitatorFailure(): bool
    {
        return $this->reason === PaymentGate::FACILITATOR_UNAVAILABLE;
    }
}
