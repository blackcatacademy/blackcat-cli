<?php

declare(strict_types=1);

namespace BlackCat\Cli\Tests;

use BlackCat\Cli\Config\CliConfig;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testConfigLoadsExampleFile(): void
    {
        $config = CliConfig::fromFile(__DIR__ . '/../config/example.cli.php');

        $install = $config->command('install');
        self::assertStringContainsString('blackcat-install', $install['script']);
        self::assertNotNull($config->defaultShoppingList());
        self::assertFileExists((string) $config->defaultShoppingList());

        $allowed = $config->allowedRoots();
        self::assertNotSame([], $allowed);

        $commands = $config->commands();
        self::assertArrayHasKey('crypto', $commands);
        self::assertArrayHasKey('db', $commands);
    }
}
