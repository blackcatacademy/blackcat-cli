<?php

declare(strict_types=1);

namespace BlackCat\Cli\Tests;

use PHPUnit\Framework\TestCase;

final class OutPathTest extends TestCase
{
    public function testOutInCurrentDirectoryWritesFile(): void
    {
        $workspace = sys_get_temp_dir() . '/blackcat-cli-out-' . bin2hex(random_bytes(4));
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

        $cliConfigPath = $workspace . '/cli.php';
        file_put_contents($cliConfigPath, '<?php return ' . var_export([
            'workspace_root' => $workspace,
            'security' => ['allowed_roots' => [$workspace]],
            'telemetry' => [
                'events_file' => $workspace . '/events.ndjson',
                'metrics_file' => $workspace . '/metrics.prom',
            ],
            'commands' => [],
        ], true) . ';' . PHP_EOL);

        $repoRoot = dirname(__DIR__);
        $out = 'trust-tx.json';
        $cmd = [
            PHP_BINARY,
            $repoRoot . '/bin/blackcat',
            '--cli-config=' . $cliConfigPath,
            'trust',
            'tx:controller-lock-attestation',
            '--controller=0x1111111111111111111111111111111111111111',
            '--chain-id=4207',
            '--key=0x' . str_repeat('11', 32),
            '--out=' . $out,
        ];

        $res = self::runProcess($cmd, $workspace);
        self::assertSame(0, $res['exit_code'], $res['stderr']);

        $written = $workspace . DIRECTORY_SEPARATOR . $out;
        self::assertFileExists($written);

        $raw = file_get_contents($written);
        self::assertIsString($raw);

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        /** @var array<string,mixed> $decoded */

        self::assertSame(4207, $decoded['chain_id'] ?? null);
        self::assertSame('lockAttestationKey(bytes32)', $decoded['method'] ?? null);
    }

    /**
     * @param list<string> $cmd
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private static function runProcess(array $cmd, string $workdir): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, $workdir);
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
