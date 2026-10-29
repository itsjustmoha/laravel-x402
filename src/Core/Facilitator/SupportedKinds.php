<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Facilitator;

/**
 * Answer of GET /supported (x402 v2 specification section 7.3).
 */
final readonly class SupportedKinds
{
    /**
     * @param  list<array{x402Version: int, scheme: string, network: string}>  $kinds
     * @param  list<string>  $extensions
     */
    public function __construct(
        public array $kinds,
        public array $extensions = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return self|null null when the body is not a SupportedResponse
     */
    public static function fromArray(array $data): ?self
    {
        if (! is_array($data['kinds'] ?? null)) {
            return null;
        }

        $kinds = [];
        foreach ($data['kinds'] as $kind) {
            if (is_array($kind) && is_int($kind['x402Version'] ?? null) && is_string($kind['scheme'] ?? null) && is_string($kind['network'] ?? null)) {
                $kinds[] = ['x402Version' => $kind['x402Version'], 'scheme' => $kind['scheme'], 'network' => $kind['network']];
            }
        }

        $extensions = is_array($data['extensions'] ?? null)
            ? array_values(array_filter($data['extensions'], is_string(...)))
            : [];

        return new self($kinds, $extensions);
    }

    public function supports(int $x402Version, string $scheme, string $network): bool
    {
        foreach ($this->kinds as $kind) {
            if ($kind['x402Version'] === $x402Version && $kind['scheme'] === $scheme && $kind['network'] === $network) {
                return true;
            }
        }

        return false;
    }
}
