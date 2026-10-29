<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Protocol;

use Itsjustmoha\X402\Core\Asset\Asset;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;

/**
 * One entry of PaymentRequired.accepts (x402 v2 specification section 5.1.2).
 */
final readonly class PaymentRequirements
{
    public const string SCHEME_EXACT = 'exact';

    /**
     * @param  array<string, mixed>  $extra
     *
     * @throws InvalidConfiguration
     */
    public function __construct(
        public string $scheme,
        public string $network,
        public string $amount,
        public string $asset,
        public string $payTo,
        public int $maxTimeoutSeconds,
        public array $extra = [],
    ) {
        if (preg_match('/^[1-9][0-9]*$/D', $amount) !== 1) {
            throw new InvalidConfiguration(sprintf('Amount "%s" must be a positive integer in atomic units.', $amount));
        }

        if ($maxTimeoutSeconds < 1) {
            throw new InvalidConfiguration('maxTimeoutSeconds must be at least 1.');
        }
    }

    /**
     * The `exact` scheme on EVM with the EIP-3009 transfer method
     * (specs/schemes/exact/scheme_exact_evm.md). `extra` carries the token's
     * EIP-712 domain so clients can sign transferWithAuthorization.
     *
     * @throws InvalidConfiguration
     */
    public static function exactEvm(string $network, Asset $asset, string $atomicAmount, string $payTo, int $maxTimeoutSeconds): self
    {
        if (preg_match('/^0x[0-9a-fA-F]{40}$/D', $payTo) !== 1 || preg_match('/^0x0{40}$/D', $payTo) === 1) {
            throw new InvalidConfiguration(sprintf(
                'pay_to "%s" is not a valid, non-zero EVM address. Use the public address of a wallet you control.',
                $payTo,
            ));
        }

        return new self(self::SCHEME_EXACT, $network, $atomicAmount, $asset->address, $payTo, $maxTimeoutSeconds, $asset->eip712Domain());
    }

    /**
     * @return array<string, mixed> v2 wire format
     */
    public function toArray(): array
    {
        $data = [
            'scheme' => $this->scheme,
            'network' => $this->network,
            'amount' => $this->amount,
            'asset' => $this->asset,
            'payTo' => $this->payTo,
            'maxTimeoutSeconds' => $this->maxTimeoutSeconds,
        ];

        if ($this->extra !== []) {
            $data['extra'] = $this->extra;
        }

        return $data;
    }

    /**
     * v1 wire format (specs/x402-specification-v1.md section 5.1.2).
     *
     * @param  array<string, mixed>|null  $outputSchema
     * @return array<string, mixed>
     */
    public function toV1Array(string $legacyNetwork, ResourceInfo $resource, ?array $outputSchema): array
    {
        $data = [
            'scheme' => $this->scheme,
            'network' => $legacyNetwork,
            'maxAmountRequired' => $this->amount,
            'resource' => $resource->url,
            'description' => $resource->description,
            'mimeType' => $resource->mimeType,
            'outputSchema' => $outputSchema,
            'payTo' => $this->payTo,
            'maxTimeoutSeconds' => $this->maxTimeoutSeconds,
            'asset' => $this->asset,
        ];

        if ($this->extra !== []) {
            $data['extra'] = $this->extra;
        }

        return $data;
    }

    /**
     * v2 matching rule of the reference server (x402ResourceServer.ts,
     * paymentRequirementsMatchAccepted): every field except `extra` must be
     * deeply equal, and `accepted.extra` must contain every key/value of our
     * `extra` (clients may add metadata, never change ours).
     *
     * @param  array<string, mixed>  $accepted
     */
    public function isMatchedBy(array $accepted): bool
    {
        $required = $this->toArray();
        $requiredExtra = $required['extra'] ?? null;
        $acceptedExtra = $accepted['extra'] ?? null;
        unset($required['extra'], $accepted['extra']);

        if (! self::deepEquals($required, $accepted)) {
            return false;
        }

        return $requiredExtra === null || self::containsSubset($requiredExtra, $acceptedExtra);
    }

    private static function deepEquals(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        if (! is_array($a) || ! is_array($b)) {
            return $a === $b;
        }

        if (count($a) !== count($b) || array_is_list($a) !== array_is_list($b)) {
            return false;
        }

        foreach ($a as $key => $value) {
            if (! array_key_exists($key, $b) || ! self::deepEquals($value, $b[$key])) {
                return false;
            }
        }

        return true;
    }

    private static function containsSubset(mixed $expected, mixed $actual): bool
    {
        if (! is_array($expected) || array_is_list($expected)) {
            return self::deepEquals($expected, $actual);
        }

        if (! is_array($actual) || ($actual !== [] && array_is_list($actual))) {
            return false;
        }

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || ! self::containsSubset($value, $actual[$key])) {
                return false;
            }
        }

        return true;
    }
}
