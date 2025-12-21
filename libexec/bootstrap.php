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
    $cliRoot . '/vendor/autoload.php',
    $workspaceRoot . '/vendor/autoload.php',
    $workspaceRoot . '/blackcat-database-crypto/vendor/autoload.php',
    $workspaceRoot . '/blackcat-database/vendor/autoload.php',
    $workspaceRoot . '/blackcat-crypto/vendor/autoload.php',
    $workspaceRoot . '/blackcat-config/vendor/autoload.php',
    $workspaceRoot . '/blackcat-core/vendor/autoload.php',
];

foreach ($candidates as $path) {
    if (is_file($path)) {
        require $path;

        // Workspace fallbacks (some repos use "read-only vendor" or cannot install all private deps).
        $fallbacks = [
            'BlackCat\\Config\\' => $workspaceRoot . '/blackcat-config/src',
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

        return;
    }
}

fwrite(
    STDERR,
    "Cannot find an autoloader. Expected one of:\n- " . implode("\n- ", $candidates) . "\n"
);
exit(1);
