<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Facilitator;

/**
 * Answer of POST /verify (x402 v2 specification section 5.4).
 */
final readonly class VerifyResponse
{
    public function __construct(
        public bool $isValid,
        public ?string $invalidReason = null,
        public ?string $payer = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return self|null null when the body is not a VerifyResponse
     */
    public static function fromArray(array $data): ?self
    {
        if (! is_bool($data['isValid'] ?? null)) {
            return null;
        }

        return new self(
            $data['isValid'],
            is_string($data['invalidReason'] ?? null) && $data['invalidReason'] !== '' ? $data['invalidReason'] : null,
            is_string($data['payer'] ?? null) && $data['payer'] !== '' ? $data['payer'] : null,
        );
    }
}
