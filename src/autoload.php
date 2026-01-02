<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for the monorepo workspace.
 *
 * Note: `blackcat-cli` is intentionally optional. Some environments cannot run CLI at all,
 * so we keep runtime dependencies discoverable (workspace siblings) and fail gracefully.
 */

/**
 * @param array<string,string> $prefixes
 */
function blackcat_register_psr4(array $prefixes): void
{
    spl_autoload_register(static function (string $class) use ($prefixes): void {
        foreach ($prefixes as $prefix => $baseDir) {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            $file = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;

            if (is_file($file)) {
                require $file;
            }

            return;
        }
    });
}

$workspaceRoot = dirname(__DIR__, 2);

blackcat_register_psr4([
    'BlackCat\\Cli\\' => __DIR__,
]);

// Optional workspace siblings (required for manifest validation / runtime config bootstrap).
if (is_dir($workspaceRoot . '/blackcat-cli-spec/src')) {
    blackcat_register_psr4([
        'BlackCat\\CliSpec\\' => $workspaceRoot . '/blackcat-cli-spec/src',
    ]);
}

if (is_dir($workspaceRoot . '/blackcat-config/src')) {
    blackcat_register_psr4([
        'BlackCat\\Config\\' => $workspaceRoot . '/blackcat-config/src',
    ]);
}
