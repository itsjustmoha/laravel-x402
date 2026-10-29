<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Gate;

/**
 * The part of an incoming HTTP request the payment gate needs. Implement it
 * for any framework (Laravel, PSR-7, ...); BasicRequest covers plain arrays.
 */
interface RequestContext
{
    /**
     * Case-insensitive header lookup; null when the header is absent.
     */
    public function header(string $name): ?string;
}
