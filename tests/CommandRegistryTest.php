<?php

declare(strict_types=1);

namespace BlackCat\Cli\Tests;

use BlackCat\Cli\Manifest\CommandRegistry;
use PHPUnit\Framework\TestCase;

final class CommandRegistryTest extends TestCase
{
    public function testLoadsManifestFromWorkspaceRootItself(): void
    {
        $root = sys_get_temp_dir() . '/blackcat-cli-registry-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        file_put_contents($root . '/blackcat-cli.json', json_encode([
            'schema_version' => 1,
            'component' => [
                'id' => 'blackcat-dbcrypto',
                'name' => 'BlackCat DB Crypto',
            ],
            'cli' => [
                'entrypoints' => [
                    [
                        'id' => 'db-crypto',
                        'command' => 'db-crypto',
                        'summary' => 'DB crypto tools',
                        'type' => 'builtin',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $registry = CommandRegistry::fromWorkspaceRoot($root);
        self::assertSame([], $registry->errors());

        $spec = $registry->find('db-crypto');
        self::assertNotNull($spec);
        self::assertTrue($spec->isBuiltin());
        self::assertSame('blackcat-dbcrypto', $spec->componentId());
    }

    public function testLoadsValidManifestFromWorkspaceRoot(): void
    {
        $root = sys_get_temp_dir() . '/blackcat-cli-registry-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        $componentDir = $root . '/blackcat-foo';
        mkdir($componentDir, 0777, true);

        file_put_contents($componentDir . '/blackcat-cli.json', json_encode([
            'schema_version' => 1,
            'component' => [
                'id' => 'blackcat-foo',
                'name' => 'BlackCat Foo',
            ],
            'cli' => [
                'entrypoints' => [
                    [
                        'id' => 'foo',
                        'command' => 'foo',
                        'summary' => 'Foo tools',
                        'type' => 'builtin',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $registry = CommandRegistry::fromWorkspaceRoot($root);
        self::assertSame([], $registry->errors());

        $spec = $registry->find('foo');
        self::assertNotNull($spec);
        self::assertTrue($spec->isBuiltin());
        self::assertSame('blackcat-foo', $spec->componentId());
        self::assertSame('Foo tools', $spec->summary());
    }

    public function testDuplicateCommandAcrossManifestsIsReported(): void
    {
        $root = sys_get_temp_dir() . '/blackcat-cli-registry-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        $a = $root . '/blackcat-a';
        $b = $root . '/blackcat-b';
        mkdir($a, 0777, true);
        mkdir($b, 0777, true);

        $manifest = [
            'schema_version' => 1,
            'cli' => [
                'entrypoints' => [
                    ['id' => 'x', 'command' => 'dup', 'summary' => 'dup', 'type' => 'builtin'],
                ],
            ],
        ];

        file_put_contents($a . '/blackcat-cli.json', json_encode($manifest + [
            'component' => ['id' => 'blackcat-a', 'name' => 'A'],
        ], JSON_PRETTY_PRINT) . PHP_EOL);

        file_put_contents($b . '/blackcat-cli.json', json_encode($manifest + [
            'component' => ['id' => 'blackcat-b', 'name' => 'B'],
        ], JSON_PRETTY_PRINT) . PHP_EOL);

        $registry = CommandRegistry::fromWorkspaceRoot($root);
        self::assertNotSame([], $registry->errors());
        self::assertNotNull($registry->find('dup'));
    }
}
