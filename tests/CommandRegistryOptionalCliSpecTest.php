<?php

declare(strict_types=1);

namespace BlackCat\Cli\Tests;

use PHPUnit\Framework\TestCase;

final class CommandRegistryOptionalCliSpecTest extends TestCase
{
    public function testNoManifestsAndNoCliSpecDoesNotError(): void
    {
        $repoRoot = dirname(__DIR__);
        $workspace = sys_get_temp_dir() . '/blackcat-cli-workspace-' . bin2hex(random_bytes(4));
        mkdir($workspace, 0777, true);

        $result = $this->runRegistryInIsolatedPhpProcess($repoRoot, $workspace);

        self::assertSame(0, $result['errors_count']);
        self::assertSame(0, $result['commands_count']);
    }

    public function testManifestPresentAndNoCliSpecErrorsInsteadOfExecuting(): void
    {
        $repoRoot = dirname(__DIR__);
        $workspace = sys_get_temp_dir() . '/blackcat-cli-workspace-' . bin2hex(random_bytes(4));
        mkdir($workspace, 0777, true);

        file_put_contents($workspace . '/blackcat-cli.json', json_encode([
            'schema_version' => 1,
            'component' => [
                'id' => 'blackcat-demo',
                'name' => 'BlackCat Demo',
            ],
            'cli' => [
                'entrypoints' => [
                    [
                        'id' => 'demo',
                        'command' => 'demo',
                        'summary' => 'Demo',
                        'type' => 'proxy',
                        'proxy' => [
                            'runner' => 'php',
                            'script' => 'bin/demo.php',
                            'args' => [],
                        ],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $result = $this->runRegistryInIsolatedPhpProcess($repoRoot, $workspace);

        self::assertSame(1, $result['errors_count']);
        self::assertSame(0, $result['commands_count']);
        self::assertStringContainsString('Missing dependency:', $result['first_error_message']);
        self::assertStringContainsString('blackcat-cli-spec', $result['first_error_message']);
        self::assertSame($workspace . '/blackcat-cli.json', $result['first_error_manifest']);
    }

    /**
     * @return array{errors_count:int,commands_count:int,first_error_manifest:string,first_error_message:string}
     */
    private function runRegistryInIsolatedPhpProcess(string $repoRoot, string $workspaceRoot): array
    {
        $script = <<<'PHP'
<?php

declare(strict_types=1);

require_once $argv[1] . '/src/Manifest/CommandSpec.php';
require_once $argv[1] . '/src/Manifest/ManifestError.php';
require_once $argv[1] . '/src/Manifest/CommandRegistry.php';

$workspaceRoot = (string) $argv[2];
$registry = \BlackCat\Cli\Manifest\CommandRegistry::fromWorkspaceRoot($workspaceRoot);
$errors = $registry->errors();
$first = $errors[0] ?? null;

echo json_encode([
    'errors_count' => count($errors),
    'commands_count' => count($registry->commands()),
    'first_error_manifest' => $first?->manifestPath() ?? '',
    'first_error_message' => $first?->message() ?? '',
], JSON_THROW_ON_ERROR) . PHP_EOL;
PHP;

        $scriptPath = sys_get_temp_dir() . '/blackcat-cli-registry-isolated-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($scriptPath, $script);

        $proc = proc_open(
            ['php', $scriptPath, $repoRoot, $workspaceRoot],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($proc)) {
            self::fail('Failed to start isolated PHP process.');
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exit = proc_close($proc);
        unlink($scriptPath);

        if ($stdout === false) {
            self::fail('Failed to read stdout from isolated PHP process.');
        }

        self::assertSame(0, $exit, is_string($stderr) ? $stderr : '');

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

        return [
            'errors_count' => (int) ($decoded['errors_count'] ?? -1),
            'commands_count' => (int) ($decoded['commands_count'] ?? -1),
            'first_error_manifest' => (string) ($decoded['first_error_manifest'] ?? ''),
            'first_error_message' => (string) ($decoded['first_error_message'] ?? ''),
        ];
    }
}
