<?php

declare(strict_types=1);

namespace BlackCat\Cli\Tests;

use BlackCat\Cli\BlackCatCli;
use PHPUnit\Framework\TestCase;

final class ConfigRuntimeAttestationTest extends TestCase
{
    public function testConfigRuntimeAttestationComputesCanonicalSha256(): void
    {
        $configAutoload = dirname(__DIR__, 2) . '/blackcat-config/src/autoload.php';
        self::assertFileExists($configAutoload);
        require_once $configAutoload;

        $root = sys_get_temp_dir() . '/blackcat-cli-config-attest-' . bin2hex(random_bytes(4));
        mkdir($root, 0755, true);
        if (DIRECTORY_SEPARATOR !== '\\') {
            chmod($root, 0755);
        }

        file_put_contents($root . '/blackcat-cli.json', json_encode([
            'schema_version' => 1,
            'component' => [
                'id' => 'blackcat-config',
                'name' => 'BlackCat Config',
            ],
            'cli' => [
                'entrypoints' => [
                    [
                        'id' => 'config',
                        'command' => 'config',
                        'summary' => 'Runtime config tooling',
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
                        'instance_controller' => '0x1111111111111111111111111111111111111111',
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
            'config',
            'runtime',
            'attestation',
            '--path=' . $runtimeConfigPath,
            '--json',
        ]);
        $out = (string) ob_get_clean();

        self::assertSame(0, $code, $out);

        /** @var mixed $decoded */
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        /** @var array<string,mixed> $decoded */

        $attestation = $decoded['attestation'] ?? null;
        self::assertIsArray($attestation);
        self::assertSame('0x' . hash('sha256', 'blackcat.runtime_config.canonical_sha256.v1'), $attestation['key'] ?? null);

        $expectedValue = self::sha256Bytes32Canonical($runtimeConfig);
        self::assertSame(strtolower($expectedValue), strtolower((string) ($attestation['value'] ?? '')));
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
