<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use BlackCat\Cli\Config\CliConfig;

$config = CliConfig::fromFile(__DIR__ . '/../config/example.cli.php');

$install = $config->command('install');
assert(str_contains($install['script'], 'blackcat-install')); 
assert(is_file($config->defaultShoppingList() ?? ''));

$allowed = $config->allowedRoots();
assert($allowed !== []);

echo "Config loader OK\n";
