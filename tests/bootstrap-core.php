<?php

declare(strict_types=1);

/*
 * Bootstrap for the framework-free core test suite (phpunit-core.xml).
 *
 * It deliberately autoloads ONLY src/Core and tests/Core, without Composer,
 * so the suite runs anywhere PHPUnit is installed and proves that src/Core
 * has no dependency on Laravel or any other package.
 */

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Itsjustmoha\\X402\\Core\\' => __DIR__.'/../src/Core/',
        'Itsjustmoha\\X402\\Tests\\Core\\' => __DIR__.'/Core/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
});
