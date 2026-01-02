<?php

declare(strict_types=1);

namespace BlackCat\Cli\Tests;

use BlackCat\Cli\BlackCatCli;
use PHPUnit\Framework\TestCase;

final class TrustCommandTest extends TestCase
{
    public function testTrustRequestInitUsesCanonicalPolicyHashV3ByDefault(): void
    {
        $root = sys_get_temp_dir() . '/blackcat-cli-trust-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        file_put_contents($root . '/blackcat-cli.json', json_encode([
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

        $cliConfigPath = $root . '/cli.php';
        file_put_contents($cliConfigPath, '<?php return ' . var_export([
            'workspace_root' => $root,
            'security' => ['allowed_roots' => [$root]],
            'telemetry' => [
                'events_file' => $root . '/events.ndjson',
                'metrics_file' => $root . '/metrics.prom',
            ],
            'commands' => [],
        ], true) . ';' . PHP_EOL);

        $rootAuthority = '0x1111111111111111111111111111111111111111';
        $upgradeAuthority = '0x2222222222222222222222222222222222222222';
        $emergencyAuthority = '0x3333333333333333333333333333333333333333';

        $genesisRoot = '0x' . str_repeat('11', 32);
        $genesisUriHash = '0x' . str_repeat('22', 32);

        ob_start();
        $code = BlackCatCli::run([
            'blackcat',
            '--cli-config=' . $cliConfigPath,
            'trust',
            'request:init',
            '--root-authority=' . $rootAuthority,
            '--upgrade-authority=' . $upgradeAuthority,
            '--emergency-authority=' . $emergencyAuthority,
            '--genesis-root=' . $genesisRoot,
            '--genesis-uri-hash=' . $genesisUriHash,
        ]);
        $out = (string) ob_get_clean();

        self::assertSame(0, $code, $out);

        /** @var mixed $decoded */
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        /** @var array<string,mixed> $decoded */

        $policy = $decoded['policy'] ?? null;
        self::assertIsArray($policy);
        self::assertSame(3, $policy['schema_version'] ?? null);
        self::assertSame('blackcat.trust.policy', $policy['type'] ?? null);
        self::assertSame('full', $policy['mode'] ?? null);
        self::assertSame(180, $policy['max_stale_sec'] ?? null);
        self::assertSame('strict', $policy['enforcement'] ?? null);

        $expectedKey = '0x' . hash('sha256', 'blackcat.runtime_config.canonical_sha256.v1');
        self::assertSame($expectedKey, $policy['runtime_config_attestation_key'] ?? null);
        self::assertTrue((bool) ($policy['require_runtime_config_attestation'] ?? false));
        self::assertTrue((bool) ($policy['runtime_config_attestation_must_be_locked'] ?? false));

        $genesis = $decoded['genesis'] ?? null;
        self::assertIsArray($genesis);
        $policyHash = $genesis['policy_hash'] ?? null;
        self::assertIsString($policyHash);

        $expectedPolicyHash = self::sha256Bytes32Canonical($policy);
        self::assertSame(strtolower($expectedPolicyHash), strtolower($policyHash));
    }

    public function testTrustRequestInitRejectsMismatchedGenesisPolicyHash(): void
    {
        $root = sys_get_temp_dir() . '/blackcat-cli-trust-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        file_put_contents($root . '/blackcat-cli.json', json_encode([
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

        $cliConfigPath = $root . '/cli.php';
        file_put_contents($cliConfigPath, '<?php return ' . var_export([
            'workspace_root' => $root,
            'security' => ['allowed_roots' => [$root]],
            'telemetry' => [
                'events_file' => $root . '/events.ndjson',
                'metrics_file' => $root . '/metrics.prom',
            ],
            'commands' => [],
        ], true) . ';' . PHP_EOL);

        $rootAuthority = '0x1111111111111111111111111111111111111111';
        $upgradeAuthority = '0x2222222222222222222222222222222222222222';
        $emergencyAuthority = '0x3333333333333333333333333333333333333333';

        $genesisRoot = '0x' . str_repeat('11', 32);
        $genesisUriHash = '0x' . str_repeat('22', 32);
        $wrongPolicyHash = '0x' . str_repeat('aa', 32);

        $cliRoot = dirname(__DIR__);
        $cmd = [
            PHP_BINARY,
            $cliRoot . '/bin/blackcat',
            '--cli-config=' . $cliConfigPath,
            'trust',
            'request:init',
            '--root-authority=' . $rootAuthority,
            '--upgrade-authority=' . $upgradeAuthority,
            '--emergency-authority=' . $emergencyAuthority,
            '--genesis-root=' . $genesisRoot,
            '--genesis-uri-hash=' . $genesisUriHash,
            '--genesis-policy-hash=' . $wrongPolicyHash,
        ];

        $res = self::runProcess($cmd, $cliRoot);
        self::assertSame(1, $res['exit_code'], $res['stderr']);
    }

    /**
     * @param array<string,mixed> $value
     */
    private static function sha256Bytes32Canonical(array $value): string
    {
        $json = self::canonicalJsonEncode($value);
        return '0x' . hash('sha256', $json);
    }

    private static function canonicalJsonEncode(mixed $value): string
    {
        $normalized = self::canonicalJsonNormalize($value);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode canonical JSON.');
        }
        return $json;
    }

    private static function canonicalJsonNormalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $out = [];
                foreach ($value as $v) {
                    $out[] = self::canonicalJsonNormalize($v);
                }
                return $out;
            }

            $keys = array_keys($value);
            sort($keys, SORT_STRING);
            $out = [];
            foreach ($keys as $k) {
                $out[$k] = self::canonicalJsonNormalize($value[$k]);
            }
            return $out;
        }

        if (is_object($value)) {
            throw new \InvalidArgumentException('Objects are not supported in canonical JSON.');
        }

        return $value;
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
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);
        if ($exitCode === -1) {
            $exitCode = 1;
        }

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
