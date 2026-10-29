<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Exception;

use RuntimeException;

/**
 * The client sent a payment header that cannot be decoded into a PaymentPayload.
 *
 * The error code is one of the x402 error codes from section 9 of the v2
 * specification ("invalid_payload" or "invalid_x402_version").
 */
final class InvalidPaymentHeader extends RuntimeException implements X402Exception
{
    public const string INVALID_PAYLOAD = 'invalid_payload';

    public const string INVALID_VERSION = 'invalid_x402_version';

    private function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function malformed(string $message): self
    {
        return new self(self::INVALID_PAYLOAD, $message);
    }

    public static function unsupportedVersion(string $message): self
    {
        return new self(self::INVALID_VERSION, $message);
    }
}
