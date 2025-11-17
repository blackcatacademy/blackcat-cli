<?php

declare(strict_types=1);

$prefix = 'BlackCat\\Cli\\';
$baseDir = __DIR__ . '/';

spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    $file = $baseDir . $relativePath;

    if (is_file($file)) {
        require $file;
    }
});
