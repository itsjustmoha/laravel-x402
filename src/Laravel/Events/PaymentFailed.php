<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Laravel\Events;

use Itsjustmoha\X402\Core\Protocol\PaymentRequirements;

/**
 * A presented payment did not unlock the resource, either during verification
 * (stage "verify", the handler never ran) or during settlement (stage
 * "settle", the handler ran but its response was replaced).
 */
final readonly class PaymentFailed
{
    public const string STAGE_VERIFY = 'verify';

    public const string STAGE_SETTLE = 'settle';

    /**
     * @param  self::STAGE_*  $stage
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $stage,
        public string $reason,
        public int $status,
        public ?PaymentRequirements $requirements,
        public ?string $payer,
    ) {}
}
