<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Laravel\Events;

use Itsjustmoha\X402\Core\Protocol\PaymentRequirements;
use Itsjustmoha\X402\Core\Protocol\ProtocolVersion;

/**
 * The facilitator accepted the payment; the protected handler runs next.
 * Nothing has been charged yet.
 */
final readonly class PaymentVerified
{
    public function __construct(
        public string $method,
        public string $path,
        public PaymentRequirements $requirements,
        public ?string $payer,
        public ProtocolVersion $version,
    ) {}
}
