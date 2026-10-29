<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Laravel\Cache;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;
use Itsjustmoha\X402\Core\Gate\PaymentLock;

/**
 * PaymentLock backed by Laravel atomic cache locks. Use a store shared by all
 * app servers (redis, memcached, database, dynamodb) in production.
 */
final readonly class CachePaymentLock implements PaymentLock
{
    public function __construct(
        private CacheFactory $cache,
        private ?string $store = null,
    ) {}

    public function acquire(string $key, int $ttlSeconds): ?string
    {
        $owner = bin2hex(random_bytes(16));

        return $this->provider()->lock($key, $ttlSeconds, $owner)->get() === true ? $owner : null;
    }

    public function release(string $key, string $owner): void
    {
        $this->provider()->restoreLock($key, $owner)->release();
    }

    private function provider(): LockProvider
    {
        $store = $this->cache->store($this->store)->getStore();

        if (! $store instanceof LockProvider) {
            throw new InvalidConfiguration(sprintf(
                'The cache store "%s" does not support atomic locks; set x402.lock_store to a store that does (redis, database, memcached, dynamodb, file, array).',
                $this->store ?? 'default',
            ));
        }

        return $store;
    }
}
