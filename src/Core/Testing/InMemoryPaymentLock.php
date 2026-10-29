<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Testing;

use Closure;
use Itsjustmoha\X402\Core\Gate\PaymentLock;

/**
 * Process-local PaymentLock for tests and single-process servers. It does not
 * protect across PHP-FPM workers; use a shared cache lock in production.
 */
final class InMemoryPaymentLock implements PaymentLock
{
    /** @var array<string, array{owner: string, expiresAt: int}> */
    private array $locks = [];

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock  returns the current unix time (injectable for tests)
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    public function acquire(string $key, int $ttlSeconds): ?string
    {
        $now = ($this->clock)();
        $current = $this->locks[$key] ?? null;

        if ($current !== null && $current['expiresAt'] > $now) {
            return null;
        }

        $owner = bin2hex(random_bytes(16));
        $this->locks[$key] = ['owner' => $owner, 'expiresAt' => $now + $ttlSeconds];

        return $owner;
    }

    public function release(string $key, string $owner): void
    {
        if (($this->locks[$key]['owner'] ?? null) === $owner) {
            unset($this->locks[$key]);
        }
    }

    public function isLocked(string $key): bool
    {
        return isset($this->locks[$key]) && $this->locks[$key]['expiresAt'] > ($this->clock)();
    }
}
