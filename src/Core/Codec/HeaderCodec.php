<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Codec;

use Itsjustmoha\X402\Core\Exception\InvalidPaymentHeader;
use JsonException;

/**
 * Base64(JSON) codec used by every x402 HTTP header
 * (PAYMENT-REQUIRED, PAYMENT-SIGNATURE, PAYMENT-RESPONSE and the v1 X-PAYMENT*
 * headers), see specs/transports-v2/http.md.
 */
final class HeaderCodec
{
    /** Generous upper bound; a typical EVM exact payload is ~1.5 KiB encoded. */
    public const int MAX_HEADER_LENGTH = 32768;

    private function __construct() {}

    /**
     * @param  array<string, mixed>  $value
     *
     * @throws JsonException
     */
    public static function encode(array $value): string
    {
        return base64_encode(Json::encode($value));
    }

    /**
     * @return array{json: string, data: array<string, mixed>} the validated JSON text and its decoded form
     *
     * @throws InvalidPaymentHeader
     */
    public static function decode(string $header): array
    {
        $header = trim($header);

        if ($header === '') {
            throw InvalidPaymentHeader::malformed('Payment header is empty.');
        }

        if (strlen($header) > self::MAX_HEADER_LENGTH) {
            throw InvalidPaymentHeader::malformed('Payment header is too large.');
        }

        if (preg_match('#^[A-Za-z0-9+/]+={0,2}$#D', $header) !== 1) {
            throw InvalidPaymentHeader::malformed('Payment header is not standard base64.');
        }

        $padded = str_pad($header, (int) (ceil(strlen($header) / 4) * 4), '=');
        $json = base64_decode($padded, true);

        if ($json === false) {
            throw InvalidPaymentHeader::malformed('Payment header is not valid base64.');
        }

        $data = Json::decodeObject($json);

        if ($data === null) {
            throw InvalidPaymentHeader::malformed('Payment header does not contain a JSON object.');
        }

        return ['json' => $json, 'data' => $data];
    }
}
