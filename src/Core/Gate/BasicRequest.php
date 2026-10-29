<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Gate;

/**
 * RequestContext backed by a header array.
 */
final readonly class BasicRequest implements RequestContext
{
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param  array<string, string|array<array-key, string|null>>  $headers  header name => value (first value wins for lists)
     */
    public function __construct(array $headers)
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $first = is_array($value) ? ($value[0] ?? null) : $value;

            if (is_string($first)) {
                $normalized[strtolower($name)] = $first;
            }
        }

        $this->headers = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
