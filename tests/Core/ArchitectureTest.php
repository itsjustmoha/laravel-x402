<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Core;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ArchitectureTest extends TestCase
{
    public function test_core_has_no_framework_or_third_party_dependencies(): void
    {
        $forbidden = '/\b(?:Illuminate|Symfony|GuzzleHttp|Psr|Orchestra)\\\\/';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src/Core', FilesystemIterator::SKIP_DOTS));
        $checked = 0;

        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            self::assertSame(0, preg_match($forbidden, $source), $file->getPathname().' must not depend on frameworks or third-party packages');
            self::assertStringContainsString('declare(strict_types=1);', $source, $file->getPathname());
            $checked++;
        }

        self::assertGreaterThan(30, $checked);
    }

    public function test_every_core_class_is_final_or_an_interface_or_enum(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src', FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $declaresType = preg_match('/^(final (readonly )?class|interface|enum) \w+/m', $source) === 1;

            self::assertTrue($declaresType, $file->getPathname().' must declare a final class, an interface or an enum');
        }
    }
}
