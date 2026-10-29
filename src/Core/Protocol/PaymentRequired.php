<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Protocol;

use InvalidArgumentException;

/**
 * The PaymentRequired object (x402 v2 specification section 5.1), sent base64
 * encoded in the PAYMENT-REQUIRED header of a 402 response.
 */
final readonly class PaymentRequired
{
    /**
     * @param  list<PaymentRequirements>  $accepts
     * @param  array<string, mixed>  $extensions  keyed by extension id, e.g. "bazaar"
     */
    public function __construct(
        public ResourceInfo $resource,
        public array $accepts,
        public array $extensions = [],
        public ?string $error = null,
    ) {
        if ($accepts === []) {
            throw new InvalidArgumentException('PaymentRequired needs at least one PaymentRequirements entry.');
        }
    }

    public function withError(string $error): self
    {
        return new self($this->resource, $this->accepts, $this->extensions, $error);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['x402Version' => ProtocolVersion::V2->value];

        if ($this->error !== null) {
            $data['error'] = $this->error;
        }

        $data['resource'] = $this->resource->toArray();
        $data['accepts'] = array_map(static fn (PaymentRequirements $r): array => $r->toArray(), $this->accepts);

        if ($this->extensions !== []) {
            $data['extensions'] = $this->extensions;
        }

        return $data;
    }

    /**
     * The v1 PaymentRequirementsResponse body (specs/transports-v1/http.md).
     * Entries on networks that never had a v1 name are left out.
     *
     * @return array<string, mixed>|null null when no entry can be expressed in v1
     */
    public function toV1Array(): ?array
    {
        $accepts = array_values(array_filter(array_map($this->v1RequirementsFor(...), $this->accepts)));

        if ($accepts === []) {
            return null;
        }

        return [
            'x402Version' => ProtocolVersion::V1->value,
            'error' => $this->error ?? 'X-PAYMENT header is required',
            'accepts' => $accepts,
        ];
    }

    /**
     * One accepts entry in v1 wire format, as sent to the facilitator for v1 payments.
     *
     * @return array<string, mixed>|null null when the network never had a v1 name
     */
    public function v1RequirementsFor(PaymentRequirements $requirements): ?array
    {
        $legacyNetwork = LegacyNetworks::nameFor($requirements->network);

        return $legacyNetwork === null ? null : $requirements->toV1Array($legacyNetwork, $this->resource, $this->v1OutputSchema());
    }

    /**
     * v1 had no extension mechanism; discovery data lived in outputSchema
     * (specs/extensions/bazaar.md, "Backwards Compatibility").
     *
     * @return array<string, mixed>|null
     */
    private function v1OutputSchema(): ?array
    {
        $bazaar = $this->extensions['bazaar'] ?? null;

        if (! is_array($bazaar) || ! is_array($bazaar['info'] ?? null) || ! is_array($bazaar['info']['input'] ?? null)) {
            return null;
        }

        return [
            'input' => $bazaar['info']['input'] + ['discoverable' => true],
            'output' => $bazaar['info']['output'] ?? null,
        ];
    }
}
