<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Codec;

use JsonException;

/**
 * JSON helpers with the encoding flags the x402 reference SDKs effectively use
 * (JavaScript JSON.stringify: no escaped slashes, raw UTF-8).
 */
final class Json
{
    private const int DEPTH = 64;

    private function __construct() {}

    /**
     * @throws JsonException
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decodes a JSON document whose top-level value must be an object.
     *
     * @return array<string, mixed>|null null when the text is not a JSON object
     */
    public static function decodeObject(string $json): ?array
    {
        if (! str_starts_with(ltrim($json), '{')) {
            return null;
        }

        try {
            $data = json_decode($json, true, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        /** @var array<string, mixed> $data a document starting with "{" always decodes to an array */
        return $data;
    }

    /**
     * Recursively sorts object keys so that semantically equal documents produce
     * identical encodings (used for lock fingerprints).
     */
    public static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map(self::canonicalize(...), $value);

        if (! array_is_list($canonical)) {
            ksort($canonical, SORT_STRING);
        }

        return $canonical;
    }
}
