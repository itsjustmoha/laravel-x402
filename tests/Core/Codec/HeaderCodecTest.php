<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Core\Codec;

use Itsjustmoha\X402\Core\Codec\HeaderCodec;
use Itsjustmoha\X402\Core\Exception\InvalidPaymentHeader;
use Itsjustmoha\X402\Tests\Core\Support\SpecExamples;
use PHPUnit\Framework\TestCase;

final class HeaderCodecTest extends TestCase
{
    public function test_decodes_the_specification_payment_signature_example(): void
    {
        $decoded = HeaderCodec::decode(SpecExamples::PAYMENT_SIGNATURE);

        self::assertSame(2, $decoded['data']['x402Version']);
        self::assertSame('eip155:84532', $decoded['data']['accepted']['network']);
        self::assertSame('10000', $decoded['data']['payload']['authorization']['value']);
        self::assertSame(base64_decode(SpecExamples::PAYMENT_SIGNATURE, true), $decoded['json']);
    }

    public function test_round_trips_without_escaping_slashes(): void
    {
        $value = ['url' => 'https://api.example.com/a/b', 'n' => 60, 'ok' => true];
        $encoded = HeaderCodec::encode($value);

        self::assertSame('{"url":"https://api.example.com/a/b","n":60,"ok":true}', base64_decode($encoded, true));
        self::assertSame($value, HeaderCodec::decode($encoded)['data']);
    }

    public function test_accepts_missing_padding_and_surrounding_whitespace(): void
    {
        $encoded = rtrim(base64_encode('{"a":1}'), '=');

        self::assertSame(['a' => 1], HeaderCodec::decode('  '.$encoded."\t")['data']);
    }

    public function test_rejects_invalid_headers(): void
    {
        $invalid = [
            'empty' => '',
            'not base64' => '!!!not-base64!!!',
            'url-safe alphabet' => strtr(base64_encode('{"a":"???>>>"}'), '+/', '-_'),
            'not json' => base64_encode('hello'),
            'json list' => base64_encode('[1,2]'),
            'json scalar' => base64_encode('"x"'),
            'truncated json' => base64_encode('{"a":'),
            'invalid utf-8' => base64_encode("{\"a\":\"\xff\"}"),
            'too large' => str_repeat('A', HeaderCodec::MAX_HEADER_LENGTH + 4),
        ];

        foreach ($invalid as $label => $header) {
            try {
                HeaderCodec::decode($header);
                self::fail(sprintf('Header "%s" should have been rejected.', $label));
            } catch (InvalidPaymentHeader $exception) {
                self::assertSame(InvalidPaymentHeader::INVALID_PAYLOAD, $exception->errorCode, $label);
            }
        }
    }
}
