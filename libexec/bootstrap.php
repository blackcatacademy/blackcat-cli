<?php

declare(strict_types=1);

/**
 * Shared bootstrap for `blackcat-cli` internal libexec scripts.
 *
 * These scripts are executed as subprocesses (so they can keep their existing
 * argv/exit semantics), but the CLI logic lives in one place: `blackcat-cli`.
 */

$cliRoot = dirname(__DIR__);
$workspaceRoot = dirname(__DIR__, 2);

$candidates = [
    $workspaceRoot . '/vendor/autoload.php',
    $workspaceRoot . '/blackcat-database-crypto/vendor/autoload.php',
    $workspaceRoot . '/blackcat-database/vendor/autoload.php',
    $workspaceRoot . '/blackcat-crypto/vendor/autoload.php',
    $workspaceRoot . '/blackcat-config/vendor/autoload.php',
    $workspaceRoot . '/blackcat-core/vendor/autoload.php',
    $cliRoot . '/vendor/autoload.php',
];

foreach ($candidates as $path) {
    if (is_file($path)) {
        require $path;

        // Workspace fallbacks (some repos use "read-only vendor" or cannot install all private deps).
        $fallbacks = [
            'BlackCat\\DatabaseCrypto\\' => $workspaceRoot . '/blackcat-database-crypto/src',
            'BlackCat\\Database\\' => $workspaceRoot . '/blackcat-database/src',
            'BlackCat\\Crypto\\' => $workspaceRoot . '/blackcat-crypto/src',
            'BlackCat\\Config\\' => $workspaceRoot . '/blackcat-config/src',
            'BlackCat\\Core\\' => $workspaceRoot . '/blackcat-core/src',
        ];

        foreach ($fallbacks as $prefix => $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            spl_autoload_register(
                static function (string $class) use ($prefix, $dir): void {
                    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                        return;
                    }
                    $relative = substr($class, strlen($prefix));
                    $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                        . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }
                },
                true,
                true
            );
        }

        // Autoload blackcat-database generated packages (packages/*/src) when available.
        $dbRoot = $workspaceRoot . '/blackcat-database';
        if (is_dir($dbRoot . '/packages')) {
            $packagesDir = $dbRoot . '/packages';
            $map = []; // ['Orders' => '/path/to/packages/orders/src', ...]

            $entries = @scandir($packagesDir);
            if (is_array($entries)) {
                foreach ($entries as $pkgFolder) {
                    if ($pkgFolder === '.' || $pkgFolder === '..') {
                        continue;
                    }
                    $src = $packagesDir . '/' . $pkgFolder . '/src';
                    if (!is_dir($src)) {
                        continue;
                    }

                    $parts = preg_split('/[_-]+/', $pkgFolder) ?: [];
                    $pascal = implode('', array_map(static fn (string $p): string => $p === '' ? '' : ucfirst($p), $parts));
                    if ($pascal !== '') {
                        $map[$pascal] = $src;
                    }
                }
            }

            if ($map !== []) {
                spl_autoload_register(static function (string $class) use ($map): void {
                    $prefix = 'BlackCat\\Database\\Packages\\';
                    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                        return;
                    }

                    $relative = substr($class, strlen($prefix)); // e.g. Orders\Repository\OrderRepository
                    $parts = explode('\\', $relative);
                    $pkg = array_shift($parts);
                    if (!is_string($pkg) || $pkg === '') {
                        return;
                    }

                    $base = $map[$pkg] ?? null;
                    if (!is_string($base) || $base === '') {
                        return;
                    }

                    $file = $base . '/' . str_replace('\\', '/', implode('\\', $parts)) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }
                }, true, true);
            }
        }

        return;
    }
}

fwrite(
    STDERR,
    "Cannot find an autoloader. Expected one of:\n- " . implode("\n- ", $candidates) . "\n"
);
exit(1);
