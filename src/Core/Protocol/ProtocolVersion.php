<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Protocol;

/**
 * x402 protocol versions and their HTTP header names.
 *
 * v2: specs/transports-v2/http.md (PAYMENT-REQUIRED / PAYMENT-SIGNATURE / PAYMENT-RESPONSE)
 * v1: specs/transports-v1/http.md (402 JSON body / X-PAYMENT / X-PAYMENT-RESPONSE)
 */
enum ProtocolVersion: int
{
    case V1 = 1;
    case V2 = 2;

    /** Server -> client, carries the base64 PaymentRequired object (v2 only). */
    public const string PAYMENT_REQUIRED_HEADER = 'PAYMENT-REQUIRED';

    /** Client -> server header carrying the base64 PaymentPayload. */
    public function paymentHeader(): string
    {
        return match ($this) {
            self::V2 => 'PAYMENT-SIGNATURE',
            self::V1 => 'X-PAYMENT',
        };
    }

    /** Server -> client header carrying the base64 SettlementResponse. */
    public function responseHeader(): string
    {
        return match ($this) {
            self::V2 => 'PAYMENT-RESPONSE',
            self::V1 => 'X-PAYMENT-RESPONSE',
        };
    }
}
