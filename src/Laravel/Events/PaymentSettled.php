<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Laravel\Events;

use Itsjustmoha\X402\Core\Facilitator\SettlementResponse;
use Itsjustmoha\X402\Core\Protocol\PaymentRequirements;
use Itsjustmoha\X402\Core\Protocol\ProtocolVersion;

/**
 * Funds moved from the payer to your pay_to address ($settlement->transaction
 * is the on-chain transaction hash).
 */
final readonly class PaymentSettled
{
    public function __construct(
        public string $method,
        public string $path,
        public PaymentRequirements $requirements,
        public SettlementResponse $settlement,
        public ProtocolVersion $version,
    ) {}
}
