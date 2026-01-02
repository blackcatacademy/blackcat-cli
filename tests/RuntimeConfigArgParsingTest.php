<?php

declare(strict_types=1);

namespace BlackCat\Cli\Tests;

use PHPUnit\Framework\TestCase;

final class RuntimeConfigArgParsingTest extends TestCase
{
    public function testVerifyRejectsMissingValueForConfigFlag(): void
    {
        $workspace = $this->createWorkspaceWithTrustEntrypoint();
        $cliConfig = $this->writeCliConfig($workspace);

        $repoRoot = dirname(__DIR__);
        $cmd = [
            PHP_BINARY,
            $repoRoot . '/bin/blackcat',
            '--cli-config=' . $cliConfig,
            'verify',
            '--config',
            '--json',
        ];

        $res = self::runProcess($cmd, $repoRoot);

        self::assertSame(1, $res['exit_code'], $res['stderr']);
        self::assertStringContainsString('Missing value for --config', $res['stderr']);
    }

    public function testTrustTxRejectsConfigFlagWhenFollowedByAnotherFlag(): void
    {
        $workspace = $this->createWorkspaceWithTrustEntrypoint();
        $cliConfig = $this->writeCliConfig($workspace);

        $repoRoot = dirname(__DIR__);
        $cmd = [
            PHP_BINARY,
            $repoRoot . '/bin/blackcat',
            '--cli-config=' . $cliConfig,
            'trust',
            'tx:controller-lock-attestation',
            '--config',
            '--chain-id=4207',
            '--controller=0x1111111111111111111111111111111111111111',
            '--key=0x' . str_repeat('11', 32),
        ];

        $res = self::runProcess($cmd, $repoRoot);

        self::assertSame(1, $res['exit_code'], $res['stderr']);
        self::assertStringContainsString('Missing value for --config', $res['stderr']);
    }

    private function createWorkspaceWithTrustEntrypoint(): string
    {
        $workspace = sys_get_temp_dir() . '/blackcat-cli-workspace-' . bin2hex(random_bytes(4));
        mkdir($workspace, 0777, true);

        file_put_contents($workspace . '/blackcat-cli.json', json_encode([
            'schema_version' => 1,
            'component' => [
                'id' => 'blackcat-kernel-contracts',
                'name' => 'BlackCat Trust Kernel Contracts',
            ],
            'cli' => [
                'entrypoints' => [
                    [
                        'id' => 'trust',
                        'command' => 'trust',
                        'summary' => 'Trust kernel helpers',
                        'type' => 'builtin',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return $workspace;
    }

    private function writeCliConfig(string $workspaceRoot): string
    {
        $path = $workspaceRoot . '/cli.php';
        file_put_contents($path, '<?php return ' . var_export([
            'workspace_root' => $workspaceRoot,
            'security' => ['allowed_roots' => [$workspaceRoot]],
            'telemetry' => [
                'events_file' => $workspaceRoot . '/events.ndjson',
                'metrics_file' => $workspaceRoot . '/metrics.prom',
            ],
            'commands' => [],
        ], true) . ';' . PHP_EOL);

        return $path;
    }

    /**
     * @param list<string> $cmd
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private static function runProcess(array $cmd, string $cwd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Unable to start process.');
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
