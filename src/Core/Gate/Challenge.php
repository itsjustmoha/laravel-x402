<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Gate;

use Itsjustmoha\X402\Core\Protocol\PaymentRequired;

/**
 * The request carried no payment: answer 402 with the payment requirements.
 */
final readonly class Challenge
{
    public function __construct(
        public PaymentRequired $paymentRequired,
        public HttpReply $reply,
    ) {}
}
