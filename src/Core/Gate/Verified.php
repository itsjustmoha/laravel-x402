<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Gate;

use Itsjustmoha\X402\Core\Facilitator\VerifyResponse;
use Itsjustmoha\X402\Core\Protocol\PaymentPayload;
use Itsjustmoha\X402\Core\Protocol\PaymentRequired;
use Itsjustmoha\X402\Core\Protocol\PaymentRequirements;

/**
 * The facilitator confirmed the payment is valid. Run the protected handler,
 * then call PaymentGate::settle() on success or PaymentGate::abandon() on failure.
 */
final readonly class Verified
{
    public function __construct(
        public PaymentRequired $offer,
        public PaymentRequirements $requirements,
        public PaymentPayload $payment,
        public VerifyResponse $verification,
        public string $lockKey,
        public string $lockOwner,
    ) {}

    public function payer(): ?string
    {
        return $this->verification->payer ?? $this->payment->claimedPayer();
    }
}
