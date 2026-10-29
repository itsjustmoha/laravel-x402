<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Protocol;

use Itsjustmoha\X402\Core\Codec\HeaderCodec;
use Itsjustmoha\X402\Core\Codec\Json;
use Itsjustmoha\X402\Core\Exception\InvalidPaymentHeader;

/**
 * A decoded client payment (x402 v2 specification section 5.2, v1 section 5.2).
 *
 * The original JSON text is kept verbatim in $json and forwarded to the
 * facilitator unchanged, so empty objects, key order and large numbers survive
 * the round trip through PHP arrays.
 */
final readonly class PaymentPayload
{
    /**
     * @param  array<string, mixed>  $accepted  v2: the PaymentRequirements the client chose; v1: empty
     * @param  array<string, mixed>  $payload  scheme specific data (signature + authorization for exact/EVM)
     */
    private function __construct(
        public ProtocolVersion $version,
        public string $scheme,
        public string $network,
        public array $accepted,
        public array $payload,
        public string $json,
    ) {}

    /**
     * @throws InvalidPaymentHeader
     */
    public static function fromHeader(string $header): self
    {
        ['json' => $json, 'data' => $data] = HeaderCodec::decode($header);

        $version = is_int($data['x402Version'] ?? null) ? ProtocolVersion::tryFrom($data['x402Version']) : null;

        if ($version === null) {
            throw InvalidPaymentHeader::unsupportedVersion('x402Version must be 1 or 2.');
        }

        $payload = self::object($data, 'payload');

        if ($version === ProtocolVersion::V2) {
            $accepted = self::object($data, 'accepted');

            return new self($version, self::string($accepted, 'scheme'), self::string($accepted, 'network'), $accepted, $payload, $json);
        }

        return new self($version, self::string($data, 'scheme'), self::string($data, 'network'), [], $payload, $json);
    }

    /**
     * Address that signed the payment, when the scheme payload exposes it
     * (EIP-3009 `authorization.from` or Permit2 `permit2Authorization.from`).
     * Informational only; the facilitator's answer is authoritative.
     */
    public function claimedPayer(): ?string
    {
        foreach (['authorization', 'permit2Authorization'] as $key) {
            $from = is_array($this->payload[$key] ?? null) ? ($this->payload[$key]['from'] ?? null) : null;

            if (is_string($from) && $from !== '') {
                return $from;
            }
        }

        return null;
    }

    /**
     * Stable identifier of this signed payment, independent of JSON key order
     * or whitespace. Used as the key of the double-spend guard lock.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->network."\n".Json::encode(Json::canonicalize($this->payload)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws InvalidPaymentHeader
     */
    private static function object(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw InvalidPaymentHeader::malformed(sprintf('Payment payload field "%s" must be an object.', $key));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidPaymentHeader
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw InvalidPaymentHeader::malformed(sprintf('Payment payload field "%s" must be a non-empty string.', $key));
        }

        return $value;
    }
}
