<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Core\Discovery;

use Itsjustmoha\X402\Core\Discovery\ServiceMetadata;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;
use Itsjustmoha\X402\Core\Protocol\ResourceInfo;
use PHPUnit\Framework\TestCase;

final class ServiceMetadataTest extends TestCase
{
    public function test_service_name_rules(): void
    {
        self::assertTrue(ServiceMetadata::isValidServiceName('Example Weather'));
        self::assertTrue(ServiceMetadata::isValidServiceName(str_repeat('a', 32)));
        self::assertFalse(ServiceMetadata::isValidServiceName(str_repeat('a', 33)));
        self::assertFalse(ServiceMetadata::isValidServiceName(''));
        self::assertFalse(ServiceMetadata::isValidServiceName("Tab\tName"));
        self::assertFalse(ServiceMetadata::isValidServiceName('Wetterdienst München'));
    }

    public function test_tags_are_filtered_deduplicated_and_truncated(): void
    {
        self::assertSame(
            ['weather', 'Forecast', 'a', 'b', 'c'],
            ServiceMetadata::sanitizeTags(['weather', 'Forecast', 'WEATHER', '', 42, str_repeat('x', 40), 'a', 'b', 'c', 'd']),
        );
    }

    public function test_icon_url_rules(): void
    {
        self::assertTrue(ServiceMetadata::isValidIconUrl('https://api.example.com/icon.png'));
        self::assertTrue(ServiceMetadata::isValidIconUrl('http://cdn.example.org/i.svg'));

        foreach ([
            'data:image/png;base64,AAAA',
            'file:///etc/passwd',
            'ftp://example.com/i.png',
            '/relative/icon.png',
            'https://user@example.com/i.png',
            'https://127.0.0.1/i.png',
            'https://[::1]/i.png',
            'https://localhost/i.png',
            'https://LOCALHOST./i.png',
            'https://2130706433/i.png',
            'https://0x7f000001/i.png',
            'https://%6c%6f%63%61%6c%68%6f%73%74/i.png',
            "https://example.com/\nicon.png",
            'https://example.com/'.str_repeat('a', 2048),
        ] as $url) {
            self::assertFalse(ServiceMetadata::isValidIconUrl($url), $url);
        }
    }

    public function test_resource_info_soft_drops_invalid_metadata(): void
    {
        $info = ResourceInfo::create('https://api.example.com/r', 'desc', 'application/json', str_repeat('n', 40), ['ok', 'ok'], 'https://localhost/i.png');

        self::assertSame(['url' => 'https://api.example.com/r', 'description' => 'desc', 'mimeType' => 'application/json', 'tags' => ['ok']], $info->toArray());
    }

    public function test_resource_url_must_be_absolute(): void
    {
        $this->expectException(InvalidConfiguration::class);

        ResourceInfo::create('/premium-data');
    }
}
