<?php

declare(strict_types=1);

namespace BlackCat\Cli\Tests;

use BlackCat\Cli\BlackCatCli;
use PHPUnit\Framework\TestCase;

final class TrustControllerTxTest extends TestCase
{
    public function testTxControllerSetAttestationBuildsExpectedCalldata(): void
    {
        $root = self::makeWorkspaceRoot();
        $cliConfigPath = self::writeCliConfig($root);

        $controller = '0x1111111111111111111111111111111111111111';
        $key = '0x' . str_repeat('11', 32);
        $value = '0x' . str_repeat('22', 32);

        ob_start();
        $code = BlackCatCli::run([
            'blackcat',
            '--cli-config=' . $cliConfigPath,
            'trust',
            'tx:controller-set-attestation',
            '--controller=' . $controller,
            '--chain-id=4207',
            '--key=' . $key,
            '--value=' . $value,
            '--json',
        ]);
        $out = (string) ob_get_clean();

        self::assertSame(0, $code, $out);

        /** @var mixed $decoded */
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        /** @var array<string,mixed> $decoded */

        self::assertSame(4207, $decoded['chain_id'] ?? null);
        self::assertSame(strtolower($controller), $decoded['to'] ?? null);
        self::assertSame('setAttestation(bytes32,bytes32)', $decoded['method'] ?? null);

        $expectedData = '0x7ac7c3e7' . substr(strtolower($key), 2) . substr(strtolower($value), 2);
        self::assertSame($expectedData, $decoded['data'] ?? null);

        $expectedSha = hash('sha256', hex2bin(substr($expectedData, 2)) ?: '');
        self::assertSame($expectedSha, $decoded['data_sha256'] ?? null);
    }

    public function testTxControllerLockAttestationBuildsExpectedCalldata(): void
    {
        $root = self::makeWorkspaceRoot();
        $cliConfigPath = self::writeCliConfig($root);

        $controller = '0x1111111111111111111111111111111111111111';
        $key = '0x' . str_repeat('11', 32);

        ob_start();
        $code = BlackCatCli::run([
            'blackcat',
            '--cli-config=' . $cliConfigPath,
            'trust',
            'tx:controller-lock-attestation',
            '--controller=' . $controller,
            '--chain-id=4207',
            '--key=' . $key,
            '--json',
        ]);
        $out = (string) ob_get_clean();

        self::assertSame(0, $code, $out);

        /** @var mixed $decoded */
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        /** @var array<string,mixed> $decoded */

        self::assertSame(4207, $decoded['chain_id'] ?? null);
        self::assertSame(strtolower($controller), $decoded['to'] ?? null);
        self::assertSame('lockAttestationKey(bytes32)', $decoded['method'] ?? null);

        $expectedData = '0x4c7c1612' . substr(strtolower($key), 2);
        self::assertSame($expectedData, $decoded['data'] ?? null);
    }

    public function testTxControllerAttestRuntimeConfigUsesRuntimeConfigValues(): void
    {
        $configAutoload = dirname(__DIR__, 2) . '/blackcat-config/src/autoload.php';
        self::assertFileExists($configAutoload);
        require_once $configAutoload;

        $root = self::makeWorkspaceRoot();
        $cliConfigPath = self::writeCliConfig($root);

        $controller = '0x1111111111111111111111111111111111111111';

        $runtimeConfig = [
            'trust' => [
                'integrity' => [
                    'root_dir' => $root,
                    'manifest' => $root . '/integrity.manifest.json',
                ],
                'web3' => [
                    'chain_id' => 4207,
                    'rpc_endpoints' => ['https://rpc.layeredge.io'],
                    'rpc_quorum' => 1,
                    'max_stale_sec' => 180,
                    'timeout_sec' => 5,
                    'mode' => 'full',
                    'contracts' => [
                        'instance_controller' => $controller,
                    ],
                ],
            ],
        ];

        $manifestPath = $runtimeConfig['trust']['integrity']['manifest'];
        file_put_contents($manifestPath, "{}\n");
        if (DIRECTORY_SEPARATOR !== '\\') {
            chmod($manifestPath, 0644);
        }

        $runtimeConfigPath = $root . '/config.runtime.json';
        file_put_contents($runtimeConfigPath, json_encode($runtimeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        if (DIRECTORY_SEPARATOR !== '\\') {
            chmod($runtimeConfigPath, 0600);
        }

        ob_start();
        $code = BlackCatCli::run([
            'blackcat',
            '--cli-config=' . $cliConfigPath,
            'trust',
            'tx:controller-attest-runtime-config',
            '--config=' . $runtimeConfigPath,
            '--json',
        ]);
        $out = (string) ob_get_clean();

        self::assertSame(0, $code, $out);

        /** @var mixed $decoded */
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        /** @var array<string,mixed> $decoded */

        self::assertSame(4207, $decoded['chain_id'] ?? null);
        self::assertSame(strtolower($controller), $decoded['to'] ?? null);
        self::assertSame('setAttestationAndLock(bytes32,bytes32)', $decoded['method'] ?? null);

        $attestation = $decoded['args'] ?? null;
        self::assertIsArray($attestation);
        $key = (string) ($attestation['key'] ?? '');
        $value = (string) ($attestation['value'] ?? '');

        self::assertSame('0x' . hash('sha256', 'blackcat.runtime_config.canonical_sha256.v1'), strtolower($key));
        self::assertSame(strtolower(self::sha256Bytes32Canonical($runtimeConfig)), strtolower($value));

        $expectedData = '0x6538fd04' . substr(strtolower($key), 2) . substr(strtolower($value), 2);
        self::assertSame($expectedData, $decoded['data'] ?? null);
    }

    private static function makeWorkspaceRoot(): string
    {
        $root = sys_get_temp_dir() . '/blackcat-cli-trust-tx-' . bin2hex(random_bytes(4));
        mkdir($root, 0755, true);
        if (DIRECTORY_SEPARATOR !== '\\') {
            chmod($root, 0755);
        }

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

        return $root;
    }

    private static function writeCliConfig(string $workspaceRoot): string
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

    private static function sha256Bytes32Canonical(mixed $value): string
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
}

