<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Gate;

use Itsjustmoha\X402\Core\Facilitator\SettlementResponse;
use Itsjustmoha\X402\Core\Protocol\PaymentRequirements;

/**
 * Settlement succeeded. Add $headers (PAYMENT-RESPONSE, or X-PAYMENT-RESPONSE
 * for v1) to the protected response and mark it Cache-Control: private.
 */
final readonly class Settled
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public SettlementResponse $settlement,
        public PaymentRequirements $requirements,
        public array $headers,
    ) {}
}
