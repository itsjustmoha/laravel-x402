<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Gate;

/**
 * Mutual exclusion per signed payment, so one authorization cannot be used by
 * two concurrent requests before it is settled on chain.
 *
 * This is a server-side optimisation on top of the real replay protection,
 * which is the EIP-3009 nonce enforced by the token contract and checked by
 * the facilitator (x402 v2 specification section 10.1).
 */
interface PaymentLock
{
    /**
     * @return string|null an owner token when the lock was acquired, null when it is held already
     */
    public function acquire(string $key, int $ttlSeconds): ?string;

    public function release(string $key, string $owner): void;
}
