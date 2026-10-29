<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Discovery;

/**
 * Soft-drop validation of the optional service metadata on ResourceInfo
 * (serviceName, tags, iconUrl), following the "Validation Rules" table in
 * specs/extensions/bazaar.md: an invalid field is dropped, never fatal.
 */
final class ServiceMetadata
{
    private const int MAX_NAME_LENGTH = 32;

    private const int MAX_TAGS = 5;

    private const int MAX_ICON_URL_LENGTH = 2048;

    private const array LOOPBACK_HOSTS = ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'];

    private function __construct() {}

    public static function isValidServiceName(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= self::MAX_NAME_LENGTH
            && preg_match('/^[\x20-\x7E]+$/D', $name) === 1;
    }

    /**
     * @param  array<array-key, mixed>  $tags
     * @return list<string> at most five valid tags, deduplicated case-insensitively (first wins)
     */
    public static function sanitizeTags(array $tags): array
    {
        $result = [];
        $seen = [];

        foreach ($tags as $tag) {
            if (! is_string($tag) || ! self::isValidServiceName($tag)) {
                continue;
            }

            $key = strtolower($tag);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $tag;

            if (count($result) === self::MAX_TAGS) {
                break;
            }
        }

        return $result;
    }

    public static function isValidIconUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_ICON_URL_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(rawurldecode($parts['host']));
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return false;
            }
            $host = $ascii;
        }

        $host = rtrim($host, '.');

        return $host !== ''
            && ! str_contains($host, ':')
            && ! str_starts_with($host, '[')
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && ! in_array($host, self::LOOPBACK_HOSTS, true)
            && preg_match('/^[0-9]+$/D', $host) !== 1
            && preg_match('/^0x[0-9a-f]+$/D', $host) !== 1;
    }
}
