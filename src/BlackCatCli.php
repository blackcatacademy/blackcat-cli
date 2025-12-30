<?php

declare(strict_types=1);

namespace BlackCat\Cli;

use BlackCat\Cli\Config\CliConfig;
use BlackCat\Cli\Manifest\CommandRegistry;
use BlackCat\Cli\Manifest\CommandSpec;
use BlackCat\Cli\Security\IntegrationChecker;
use BlackCat\Cli\Telemetry\CliTelemetry;
use InvalidArgumentException;

final class BlackCatCli
{
    private const ABI_SELECTOR_CREATE_INSTANCE = '81ff2930';
    private const ABI_SELECTOR_PAUSED = '5c975abb';
    private const ABI_SELECTOR_ACTIVE_ROOT = 'abb2efdf';
    private const ABI_SELECTOR_ACTIVE_URI_HASH = 'ce7db111';
    private const ABI_SELECTOR_ACTIVE_POLICY_HASH = '246ce79d';
    private const ABI_SELECTOR_ROOT_AUTHORITY = '61fe51a1';
    private const ABI_SELECTOR_UPGRADE_AUTHORITY = 'dd7d7cd9';
    private const ABI_SELECTOR_EMERGENCY_AUTHORITY = '718fe851';
    private const ABI_SELECTOR_SET_ATTESTATION = '7ac7c3e7';
    private const ABI_SELECTOR_SET_ATTESTATION_AND_LOCK = '6538fd04';
    private const ABI_SELECTOR_LOCK_ATTESTATION_KEY = '4c7c1612';

    private function __construct(
        private readonly CliConfig $config,
        private readonly CliTelemetry $telemetry,
        private readonly CommandRegistry $registry
    ) {
    }

    /**
     * @param string[] $argv
     */
    public static function run(array $argv): int
    {
        [$options, $args] = self::parseArguments($argv);
        $command = $args[0] ?? 'help';
        $commandArgs = array_slice($args, 1);

        try {
            $config = CliConfig::fromFile($options['cli_config'] ?? null);
        } catch (InvalidArgumentException $e) {
            fwrite(STDERR, '[blackcat-cli] ' . $e->getMessage() . PHP_EOL);
            return 1;
        }

        $registry = CommandRegistry::fromWorkspaceRoot($config->workspaceRoot());
        $telemetry = new CliTelemetry($config->telemetryEventsFile(), $config->telemetryMetricsFile());
        $app = new self($config, $telemetry, $registry);

        $started = microtime(true);
        try {
            $exitCode = $app->dispatch($command, $commandArgs);
        } catch (\Throwable $e) {
            fwrite(STDERR, '[blackcat-cli] ' . $e->getMessage() . PHP_EOL);
            $exitCode = 1;
        } finally {
            $app->telemetry->record($command, $exitCode ?? 1, microtime(true) - $started);
            $app->telemetry->flush();
        }

        return $exitCode;
    }

    /**
     * @param string[] $argv
     * @return array{array<string,?string>,string[]}
     */
    private static function parseArguments(array $argv): array
    {
        $options = [];
        $positionals = [];
        $args = array_slice($argv, 1);

        while ($args !== []) {
            $arg = array_shift($args);
            if ($arg === '--') {
                $positionals = array_merge($positionals, $args);
                break;
            }

            if ($arg === '--cli-config') {
                $options['cli_config'] = array_shift($args) ?: null;
                continue;
            }

            if (str_starts_with((string) $arg, '--cli-config=')) {
                $options['cli_config'] = substr((string) $arg, 13) ?: null;
                continue;
            }

            $positionals[] = $arg;
        }

        return [$options, $positionals];
    }

    /**
     * @param string[] $args
     */
    private function dispatch(string $command, array $args): int
    {
        return match ($command) {
            'help' => $this->printHelp(),
            'install' => $this->runShoppingListCommand('install', $args[0] ?? null),
            'configure' => $this->runShoppingListCommand('configure', $args[0] ?? null),
            'status' => $this->runStatus($args),
            'verify' => $this->runVerify($args),
            default => $this->runAnyCommand($command, $args),
        };
    }

    private function printHelp(): int
    {
        echo "BlackCat CLI\n";
        echo "Usage: blackcat [--cli-config=path] <command> [args...]\n\n";
        echo "Commands:\n";
        echo "  configure <shopping-list.json>    Configure shopping list via installer\n";
        echo "  install <shopping-list.json>      Run blackcat-install pipeline\n";
        echo "  status [--json]                   List configured proxies + status\n";
        echo "  verify [--json] [--config=FILE]   Security + integration checks\n";
        echo "\nConfigured proxy commands:\n";

        $configured = array_keys($this->config->commands());
        sort($configured);
        foreach ($configured as $name) {
            echo "  {$name}\n";
        }

        $dynamic = $this->registry->commands();
        if ($dynamic !== []) {
            echo "\nDiscovered manifest commands:\n";
            $names = array_keys($dynamic);
            sort($names);
            foreach ($names as $name) {
                if (in_array($name, $configured, true)) {
                    continue;
                }
                $spec = $dynamic[$name];
                $type = $spec->isBuiltin() ? 'builtin' : 'proxy';
                echo sprintf("  %-16s (%s) %s\n", $name, $type, $spec->summary());
            }
        }

        if ($this->registry->errors() !== []) {
            echo "\nManifest validation errors detected: " . count($this->registry->errors()) . "\n";
            echo "Run: blackcat verify --json\n";
        }

        echo "\nSet BLACKCAT_CLI_CONFIG or pass --cli-config to point at another CLI config file.\n";
        return 0;
    }

    private function runShoppingListCommand(string $command, ?string $shoppingList): int
    {
        if ($shoppingList === null) {
            $shoppingList = $this->config->defaultShoppingList();
        }

        if ($shoppingList === null || !is_file($shoppingList)) {
            fwrite(STDERR, "Usage: blackcat {$command} <shopping-list.json>\n");
            return 1;
        }

        $spec = $this->config->command($command);
        $cmd = array_merge([$spec['runner'], $spec['script']], $spec['args'], [$shoppingList]);
        return $this->runProcess($cmd);
    }

    /**
     * @param string[] $args
     */
    private function runAnyCommand(string $command, array $args): int
    {
        try {
            $spec = $this->config->command($command);
            $cmd = array_merge([$spec['runner'], $spec['script']], $spec['args'], $args);
            return $this->runProcess($cmd);
        } catch (InvalidArgumentException) {
            // fall through
        }

        $dynamic = $this->registry->find($command);
        if ($dynamic === null) {
            return $this->unknown($command);
        }

        if ($dynamic->isBuiltin()) {
            return $this->runBuiltin($dynamic, $args);
        }

        $runner = $dynamic->runner() ?? 'php';
        $script = $dynamic->script();
        if (!is_string($script) || $script === '') {
            fwrite(STDERR, "Invalid proxy spec for '{$command}': missing script.\n");
            return 1;
        }

        $cmd = array_merge([$runner, $script], $dynamic->args(), $args);
        return $this->runProcess($cmd);
    }

    /**
     * @param string[] $args
     */
    private function runStatus(array $args): int
    {
        [$json, $remaining] = $this->consumeFlag($args, '--json');
        $remaining = self::stripRuntimeConfigArgs($remaining);
        if ($remaining !== []) {
            fwrite(STDERR, 'status does not accept additional arguments' . PHP_EOL);
            return 1;
        }

        $checker = new IntegrationChecker($this->config, $this->registry);
        $results = $checker->inspect();

        if ($json) {
            echo json_encode([
                'workspace' => $this->config->workspaceRoot(),
                'manifest_errors' => array_map(
                    static fn ($e): array => ['manifest' => $e->manifestPath(), 'message' => $e->message()],
                    $checker->manifestErrors()
                ),
                'commands' => $results,
            ], JSON_PRETTY_PRINT) . PHP_EOL;
            return 0;
        }

        echo "BlackCat CLI status\n";
        foreach ($results as $row) {
            $state = ($row['exists'] && $row['allowed']) ? 'OK' : 'MISSING';
            $path = $row['resolved'] ?? $row['script'];
            echo sprintf("  %-12s %s %s\n", $row['command'], str_pad($state, 8), $path);
        }

        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runVerify(array $args): int
    {
        [$json, $remaining] = $this->consumeFlag($args, '--json');
        [$runtimeConfigPath, $remaining] = self::consumeRuntimeConfigPath($remaining);
        if ($remaining !== []) {
            fwrite(STDERR, 'verify does not accept additional arguments' . PHP_EOL);
            return 1;
        }

        $checker = new IntegrationChecker($this->config, $this->registry);
        $results = $checker->inspect();
        $violations = array_values(array_filter(
            $results,
            static fn (array $row): bool => !$row['exists'] || !$row['allowed']
        ));

        $manifestErrors = $checker->manifestErrors();
        $doctorChecks = $this->runDoctorChecks($runtimeConfigPath);

        $doctorFailures = array_values(array_filter(
            $doctorChecks,
            static fn (array $check): bool => $check['status'] === 'fail'
        ));

        if ($json) {
            echo json_encode([
                'workspace' => $this->config->workspaceRoot(),
                'manifest_errors' => array_map(
                    static fn ($e): array => ['manifest' => $e->manifestPath(), 'message' => $e->message()],
                    $manifestErrors
                ),
                'violations' => $violations,
                'commands' => $results,
                'doctor_checks' => $doctorChecks,
            ], JSON_PRETTY_PRINT) . PHP_EOL;
        } else {
            foreach ($results as $row) {
                $path = $row['resolved'] ?? $row['script'];
                $status = ($row['exists'] && $row['allowed']) ? 'OK' : 'FAIL';
                echo sprintf('[%s] %-12s %s' . PHP_EOL, $status, $row['command'], $path);
            }

            if ($doctorChecks !== []) {
                echo PHP_EOL . "Doctor checks\n";
                foreach ($doctorChecks as $check) {
                    $name = $check['name'];
                    $status = strtoupper($check['status']);
                    $msg = $check['message'];
                    echo sprintf('[%s] %-24s %s' . PHP_EOL, $status, $name, $msg);
                }
            }
        }

        if ($manifestErrors !== []) {
            fwrite(STDERR, 'Manifest validation failed for ' . count($manifestErrors) . ' file(s).' . PHP_EOL);
        }

        if ($doctorFailures !== []) {
            fwrite(STDERR, 'Doctor checks failed.' . PHP_EOL);
        }

        if ($violations !== [] || $manifestErrors !== [] || $doctorFailures !== []) {
            fwrite(STDERR, 'Integration check failed.' . PHP_EOL);
            return 2;
        }

        return 0;
    }

    /**
     * @param string[] $args
     * @return array{0:bool,1:string[]}
     */
    private function consumeFlag(array $args, string $flag): array
    {
        $filtered = [];
        $found = false;

        foreach ($args as $arg) {
            if ($arg === $flag) {
                $found = true;
                continue;
            }
            $filtered[] = $arg;
        }

        return [$found, $filtered];
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, "Unknown command '{$command}'. Run blackcat help.\n");
        return 1;
    }

    /**
     * @param string[] $cmd
     */
    private function runProcess(array $cmd): int
    {
        $process = proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            fwrite(STDERR, 'Failed to start process: ' . implode(' ', $cmd) . PHP_EOL);
            return 1;
        }

        return proc_close($process);
    }

    /**
     * Builtin commands discovered by manifest.
     *
     * @param string[] $args
     */
    private function runBuiltin(CommandSpec $spec, array $args): int
    {
        return match ($spec->command()) {
            'db' => $this->runDb($args),
            'db-crypto' => $this->runDbCrypto($args),
            'crypto' => $this->runCrypto($args),
            'config' => $this->runConfig($args),
            'deployer' => $this->runDeployer($args),
            'monitoring' => $this->runMonitoring($args),
            'observability' => $this->runObservability($args),
            'trust' => $this->runTrust($args),
            'usage' => $this->runUsage($args),
            default => $this->unknownBuiltin($spec->command()),
        };
    }

    private function unknownBuiltin(string $command): int
    {
        fwrite(STDERR, "Builtin command '{$command}' is not implemented in blackcat-cli.\n");
        return 1;
    }

    /**
     * @param string[] $args
     */
    private function runDbCrypto(array $args): int
    {
        $sub = $args[0] ?? 'help';
        $rest = array_slice($args, 1);

        $cliRoot = dirname(__DIR__);
        $libexec = $cliRoot . '/libexec';

        $script = match ($sub) {
            'help', '--help', '-h' => null,
            'plan' => $libexec . '/db-crypto-plan',
            'health' => $libexec . '/db-crypto-health',
            'stress' => $libexec . '/db-crypto-stress',
            'telemetry' => $libexec . '/db-crypto-telemetry',
            'schema' => $libexec . '/db-crypto-schema',
            'keys-sync', 'keys' => $libexec . '/db-crypto-keys-sync',
            default => '',
        };

        if ($script === null) {
            echo "db-crypto\n";
            echo "Usage: blackcat db-crypto <subcommand> [args...]\n\n";
            echo "Subcommands:\n";
            echo "  plan         Validate encryption map vs schema/manifest\n";
            echo "  health       Crypto roundtrip + fallback smoke checks\n";
            echo "  stress       Stress test for encryption/hmac paths\n";
            echo "  telemetry    Generate map metrics summary\n";
            echo "  schema       Build schema snapshot (JSON)\n";
            echo "  keys-sync    Sync key inventory into DB\n";
            echo "\nExamples:\n";
            echo "  blackcat db-crypto telemetry --out=telemetry/db-crypto-metrics.json\n";
            echo "  blackcat db-crypto stress --iterations=20000 --out=telemetry/db-crypto-stress.json\n";
            echo "  blackcat db-crypto health --generate-keys=1 --max-contexts=25\n";
            return 0;
        }

        if ($script === '' || !is_file($script)) {
            fwrite(STDERR, "db-crypto subcommand not found: {$sub}\n");
            return 1;
        }

        $cmd = array_merge([PHP_BINARY, $script], $rest);
        return $this->runProcess($cmd);
    }

    /**
     * @param string[] $args
     */
    private function runDb(array $args): int
    {
        $sub = $args[0] ?? 'help';
        $rest = array_slice($args, 1);

        $cliRoot = dirname(__DIR__);
        $libexec = $cliRoot . '/libexec';

        $script = match ($sub) {
            'help', '--help', '-h' => null,
            'doctor' => $libexec . '/db-doctor',
            'outbox-worker', 'outbox' => $libexec . '/db-outbox-worker',
            default => $libexec . '/db',
        };

        if ($script === null) {
            echo "db\n";
            echo "Usage: blackcat db <subcommand> [args...]\n\n";
            echo "Subcommands:\n";
            echo "  ping           DB ping (requires db config)\n";
            echo "  explain        Explain SQL plan\n";
            echo "  route          Run query via primary/replica\n";
            echo "  wait-replica   Wait for replica to catch up\n";
            echo "  trace          Dump last queries (this process)\n";
            echo "  doctor         Print DB snapshot (driver/server/replica)\n";
            echo "  outbox-worker  Drain outbox table (stdout/webhook)\n";
            echo "\nDB config sources (priority):\n";
            echo "  1) --bootstrap=FILE  (your bootstrap calls Database::init)\n";
            echo "  2) --dsn=... [--user=... --password=...]\n";
            echo "  3) runtime config JSON: db.dsn, db.user, db.password (pass --config=FILE)\n";
            echo "\nExamples:\n";
            echo "  blackcat db ping --dsn=\"mysql:host=localhost;dbname=app;charset=utf8mb4\"\n";
            echo "  blackcat db explain \"SELECT 1\" --analyze\n";
            echo "  blackcat db outbox-worker --batch=200 --sleep-ms=500 --once\n";
            return 0;
        }

        if (!is_file($script)) {
            fwrite(STDERR, "db subcommand not found: {$sub}\n");
            return 1;
        }

        $passThrough = match ($sub) {
            'doctor', 'outbox-worker', 'outbox' => $rest,
            default => $args,
        };

        $cmd = array_merge([PHP_BINARY, $script], $passThrough);
        return $this->runProcess($cmd);
    }

    /**
     * @param string[] $args
     */
    private function runMonitoring(array $args): int
    {
        $sub = $args[0] ?? 'help';
        $rest = array_slice($args, 1);

        $cliRoot = dirname(__DIR__);
        $libexec = $cliRoot . '/libexec';

        $script = match ($sub) {
            'help', '--help', '-h' => null,
            'stack' => $libexec . '/monitoring-stack',
            'k8s' => $libexec . '/monitoring-k8s',
            'infra' => $libexec . '/monitoring-infra',
            'provisioning', 'assets' => $libexec . '/monitoring-provisioning',
            default => '',
        };

        if ($script === null) {
            echo "monitoring\n";
            echo "Usage: blackcat monitoring <subcommand> [args...]\n\n";
            echo "Subcommands:\n";
            echo "  stack         Manage local monitoring dev stack (docker compose)\n";
            echo "  k8s           Kubernetes manifests (list/apply/diff/validate)\n";
            echo "  infra         Terraform helper (init/plan/apply/destroy)\n";
            echo "  provisioning  Grafana dashboards (list/print/path)\n";
            echo "\nExamples:\n";
            echo "  blackcat monitoring stack info\n";
            echo "  blackcat monitoring stack up --pull\n";
            echo "  blackcat monitoring stack status\n";
            echo "  blackcat monitoring k8s list\n";
            echo "  blackcat monitoring infra plan\n";
            echo "  blackcat monitoring provisioning list\n";
            return 0;
        }

        if ($script === '' || !is_file($script)) {
            fwrite(STDERR, "monitoring subcommand not found: {$sub}\n");
            return 1;
        }

        $cmd = array_merge([PHP_BINARY, $script], $rest);
        return $this->runProcess($cmd);
    }

    /**
     * @param string[] $args
     */
    private function runDeployer(array $args): int
    {
        $sub = $args[0] ?? 'help';

        if ($sub === 'help' || $sub === '--help' || $sub === '-h') {
            echo "deployer\n";
            echo "Usage: blackcat deployer <subcommand> [args...]\n\n";
            echo "Status: bootstrap (command surface will be expanded in blackcat-deployer).\n\n";
            echo "Docs:\n";
            echo "  - blackcat-deployer/docs/ROADMAP.md\n";
            return 0;
        }

        fwrite(STDERR, "deployer subcommand not found: {$sub}\n");
        return 1;
    }

    /**
     * @param string[] $args
     */
    private function runTrust(array $args): int
    {
        $sub = $args[0] ?? 'help';
        $rest = array_slice($args, 1);

        if ($sub === 'help' || $sub === '--help' || $sub === '-h' || $sub === '') {
            echo "trust\n";
            echo "Usage: blackcat trust <subcommand> [args...]\n\n";
            echo "Subcommands:\n";
            echo "  request:init   Generate a trust-kernel setup request bundle (JSON)\n";
            echo "  tx:factory-create  Generate InstanceFactory.createInstance calldata (JSON)\n";
            echo "  tx:controller-set-attestation   Generate InstanceController.setAttestation calldata (JSON)\n";
            echo "  tx:controller-lock-attestation  Generate InstanceController.lockAttestationKey calldata (JSON)\n";
            echo "  tx:controller-attest-runtime-config  Generate setAttestationAndLock for runtime config (policy v3)\n";
            echo "  status         Show trust-kernel runtime config status\n";
            echo "  verify         Validate trust-kernel config + RPC quorum (exit 2 on failure)\n";
            echo "\nExamples:\n";
            echo "  blackcat trust request:init \\\n";
            echo "    --root-authority=0x... \\\n";
            echo "    --upgrade-authority=0x... \\\n";
            echo "    --emergency-authority=0x... \\\n";
            echo "    --rpc=https://rpc.layeredge.io --chain-id=4207 --mode=full --policy-version=3 --enforcement=strict \\\n";
            echo "    --genesis-root=0x... --genesis-uri-hash=0x...\n";
            echo "\n";
            echo "  blackcat trust tx:factory-create \\\n";
            echo "    --factory=0x... \\\n";
            echo "    --request=trust-request.json --out=trust-tx.json\n";
            echo "\n";
            echo "  blackcat trust tx:controller-attest-runtime-config \\\n";
            echo "    --config=/etc/blackcat/config.runtime.json --out=runtime-config-attestation.tx.json\n";
            echo "\n";
            echo "  blackcat trust status --json\n";
            echo "  blackcat trust verify --config=/etc/blackcat/config.runtime.json\n";
            return 0;
        }

        return match ($sub) {
            'request:init' => $this->runTrustRequestInit($rest),
            'tx:factory-create' => $this->runTrustTxFactoryCreate($rest),
            'tx:controller-set-attestation' => $this->runTrustTxControllerSetAttestation($rest),
            'tx:controller-lock-attestation' => $this->runTrustTxControllerLockAttestation($rest),
            'tx:controller-attest-runtime-config' => $this->runTrustTxControllerAttestRuntimeConfig($rest),
            'status' => $this->runTrustStatus($rest),
            'verify' => $this->runTrustVerify($rest),
            default => $this->unknown('trust ' . $sub),
        };
    }

    /**
     * Generate a JSON request bundle for creating/cloning an InstanceController on-chain.
     *
     * @param string[] $args
     */
    private function runTrustRequestInit(array $args): int
    {
        $out = null;
        $chainId = 4207;
        $rpcEndpoints = [];
        $quorum = null;
        $mode = 'full';
        $maxStaleSec = 180;
        $policyVersion = 3;
        $enforcement = 'strict';

        $rootAuthority = null;
        $upgradeAuthority = null;
        $emergencyAuthority = null;

        $genesisRoot = null;
        $genesisUriHash = null;
        $genesisPolicyHash = null;

        $expectValueFor = null;

        foreach ($args as $arg) {
            if ($expectValueFor !== null) {
                $key = $expectValueFor;
                $expectValueFor = null;
                $this->applyTrustRequestOption(
                    $key,
                    (string) $arg,
                    $out,
                    $chainId,
                    $rpcEndpoints,
                    $quorum,
                    $mode,
                    $maxStaleSec,
                    $policyVersion,
                    $enforcement,
                    $rootAuthority,
                    $upgradeAuthority,
                    $emergencyAuthority,
                    $genesisRoot,
                    $genesisUriHash,
                    $genesisPolicyHash
                );
                continue;
            }

            $arg = (string) $arg;
            if ($arg === '--out' || $arg === '--chain-id' || $arg === '--rpc' || $arg === '--quorum'
                || $arg === '--mode' || $arg === '--max-stale-sec' || $arg === '--policy-version' || $arg === '--enforcement'
                || $arg === '--root-authority' || $arg === '--upgrade-authority' || $arg === '--emergency-authority'
                || $arg === '--genesis-root' || $arg === '--genesis-uri-hash' || $arg === '--genesis-policy-hash'
            ) {
                $expectValueFor = $arg;
                continue;
            }

            if (!str_starts_with($arg, '--')) {
                fwrite(STDERR, "Unknown argument: {$arg}\n");
                return 1;
            }

            [$key, $value] = explode('=', $arg, 2) + [null, null];
            if (!is_string($key) || $key === '' || $value === null) {
                fwrite(STDERR, "Invalid option: {$arg}\n");
                return 1;
            }

            $this->applyTrustRequestOption(
                $key,
                $value,
                $out,
                $chainId,
                $rpcEndpoints,
                $quorum,
                $mode,
                $maxStaleSec,
                $policyVersion,
                $enforcement,
                $rootAuthority,
                $upgradeAuthority,
                $emergencyAuthority,
                $genesisRoot,
                $genesisUriHash,
                $genesisPolicyHash
            );
        }

        if ($expectValueFor !== null) {
            fwrite(STDERR, "Missing value for {$expectValueFor}\n");
            return 1;
        }

        if ($rpcEndpoints === []) {
            $rpcEndpoints = ['https://rpc.layeredge.io'];
        }
        if ($quorum === null) {
            $quorum = count($rpcEndpoints) >= 2 ? 2 : 1;
        }

        if ($rootAuthority === null || $upgradeAuthority === null || $emergencyAuthority === null) {
            fwrite(STDERR, "Missing required authorities.\n");
            fwrite(STDERR, "Usage: blackcat trust request:init --root-authority=0x.. --upgrade-authority=0x.. --emergency-authority=0x.. --genesis-root=0x.. --genesis-uri-hash=0x..\n");
            return 1;
        }

        $this->assertEvmAddress($rootAuthority, 'root-authority');
        $this->assertEvmAddress($upgradeAuthority, 'upgrade-authority');
        $this->assertEvmAddress($emergencyAuthority, 'emergency-authority');

        if ($chainId <= 0) {
            fwrite(STDERR, "Invalid --chain-id (expected > 0)\n");
            return 1;
        }
        if ($quorum < 1 || $quorum > count($rpcEndpoints)) {
            fwrite(STDERR, "Invalid --quorum (expected 1.." . count($rpcEndpoints) . ")\n");
            return 1;
        }
        if (!in_array($mode, ['root_uri', 'full'], true)) {
            fwrite(STDERR, "Invalid --mode (expected root_uri|full)\n");
            return 1;
        }
        if ($maxStaleSec < 1 || $maxStaleSec > 86400) {
            fwrite(STDERR, "Invalid --max-stale-sec (expected 1..86400)\n");
            return 1;
        }
        if (!in_array($policyVersion, [1, 2, 3], true)) {
            fwrite(STDERR, "Invalid --policy-version (expected 1|2|3)\n");
            return 1;
        }
        $enforcement = strtolower(trim($enforcement));
        if (!in_array($enforcement, ['strict', 'warn'], true)) {
            fwrite(STDERR, "Invalid --enforcement (expected strict|warn)\n");
            return 1;
        }

        if ($genesisRoot === null) {
            fwrite(STDERR, "Missing required --genesis-root (bytes32).\n");
            return 1;
        }
        if ($genesisUriHash === null) {
            fwrite(STDERR, "Missing required --genesis-uri-hash (bytes32).\n");
            return 1;
        }

        $this->assertBytes32($genesisRoot, 'genesis-root');
        $this->assertBytes32($genesisUriHash, 'genesis-uri-hash');

        $policyDoc = $this->buildTrustPolicyDoc($policyVersion, $mode, $maxStaleSec, $enforcement);
        $computedPolicyHash = $this->computeSha256Bytes32($policyDoc);
        if ($genesisPolicyHash === null) {
            $genesisPolicyHash = $computedPolicyHash;
        } elseif (!hash_equals(strtolower($computedPolicyHash), strtolower($genesisPolicyHash))) {
            fwrite(STDERR, "genesis-policy-hash does not match the computed policy document hash.\n");
            fwrite(STDERR, "  computed: {$computedPolicyHash}\n");
            fwrite(STDERR, "  provided: {$genesisPolicyHash}\n");
            return 1;
        }
        $this->assertBytes32($genesisPolicyHash, 'genesis-policy-hash');

        $payload = [
            'schema_version' => 1,
            'type' => 'blackcat.trust.request',
            'created_at' => gmdate('c'),
            'chain' => [
                'chain_id' => $chainId,
                'rpc_endpoints' => array_values($rpcEndpoints),
                'rpc_quorum' => $quorum,
            ],
            'trust' => [
                'mode' => $mode,
                'max_stale_sec' => $maxStaleSec,
                'policy_version' => $policyVersion,
                'enforcement' => $enforcement,
            ],
            'authorities' => [
                'root_authority' => $rootAuthority,
                'upgrade_authority' => $upgradeAuthority,
                'emergency_authority' => $emergencyAuthority,
            ],
            'policy' => $policyDoc,
            'genesis' => [
                'root' => $genesisRoot,
                'uri_hash' => $genesisUriHash,
                'policy_hash' => $genesisPolicyHash,
            ],
            'notes' => [
                'warning' => 'Draft request bundle: review on a separate device and confirm via multisig before applying on the target server.',
            ],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            fwrite(STDERR, "Unable to encode JSON\n");
            return 1;
        }

        if ($out === null) {
            echo $json . PHP_EOL;
            return 0;
        }

        try {
            $this->writeSecureJsonFile($out, $json);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 2;
        }

        echo "Wrote: {$out}\n";
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runTrustTxFactoryCreate(array $args): int
    {
        [$json, $args] = $this->consumeFlag($args, '--json');

        $factory = null;
        $out = null;
        $requestPath = null;

        $expectValueFor = null;

        foreach ($args as $arg) {
            if ($expectValueFor !== null) {
                $key = $expectValueFor;
                $expectValueFor = null;
                $val = trim((string) $arg);

                if ($key === '--factory') {
                    $factory = $val !== '' ? $val : null;
                } elseif ($key === '--out') {
                    $out = $val !== '' ? $val : null;
                } elseif ($key === '--request') {
                    $requestPath = $val !== '' ? $val : null;
                } else {
                    throw new InvalidArgumentException('Unknown option: ' . $key);
                }
                continue;
            }

            $arg = (string) $arg;
            if ($arg === '--factory' || $arg === '--out' || $arg === '--request') {
                $expectValueFor = $arg;
                continue;
            }

            if (!str_starts_with($arg, '--')) {
                fwrite(STDERR, "Unknown argument: {$arg}\n");
                return 1;
            }

            [$key, $value] = explode('=', $arg, 2) + [null, null];
            if (!is_string($key) || $key === '' || $value === null) {
                fwrite(STDERR, "Invalid option: {$arg}\n");
                return 1;
            }

            $value = trim($value);
            match ($key) {
                '--factory' => $factory = $value !== '' ? $value : null,
                '--out' => $out = $value !== '' ? $value : null,
                '--request' => $requestPath = $value !== '' ? $value : null,
                default => throw new InvalidArgumentException('Unknown option: ' . $key),
            };
        }

        if ($expectValueFor !== null) {
            fwrite(STDERR, "Missing value for {$expectValueFor}\n");
            return 1;
        }

        if ($factory === null) {
            fwrite(STDERR, "Usage: blackcat trust tx:factory-create --factory=0x... --request=FILE [--out=FILE] [--json]\n");
            return 1;
        }
        $this->assertEvmAddress($factory, 'factory');

        try {
            $raw = $this->readRequestJson($requestPath);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            fwrite(STDERR, "Invalid request JSON: {$e->getMessage()}\n");
            return 1;
        }
        if (!is_array($decoded)) {
            fwrite(STDERR, "Invalid request JSON (expected object)\n");
            return 1;
        }

        /** @var array<string,mixed> $decoded */
        $authorities = $decoded['authorities'] ?? null;
        $genesis = $decoded['genesis'] ?? null;
        $chain = $decoded['chain'] ?? null;
        $trust = $decoded['trust'] ?? null;
        $policy = $decoded['policy'] ?? null;

        if (!is_array($authorities) || !is_array($genesis)) {
            fwrite(STDERR, "Invalid request payload (missing authorities/genesis)\n");
            return 1;
        }

        $rootAuthority = $authorities['root_authority'] ?? null;
        $upgradeAuthority = $authorities['upgrade_authority'] ?? null;
        $emergencyAuthority = $authorities['emergency_authority'] ?? null;

        if (!is_string($rootAuthority) || !is_string($upgradeAuthority) || !is_string($emergencyAuthority)) {
            fwrite(STDERR, "Invalid request payload (authorities must be strings)\n");
            return 1;
        }
        $this->assertEvmAddress($rootAuthority, 'root-authority');
        $this->assertEvmAddress($upgradeAuthority, 'upgrade-authority');
        $this->assertEvmAddress($emergencyAuthority, 'emergency-authority');

        $genesisRoot = $genesis['root'] ?? null;
        $genesisUriHash = $genesis['uri_hash'] ?? null;
        $genesisPolicyHash = $genesis['policy_hash'] ?? null;

        if (!is_string($genesisRoot) || $genesisRoot === '') {
            fwrite(STDERR, "Request is missing genesis.root (required for createInstance)\n");
            return 1;
        }
        $this->assertBytes32($genesisRoot, 'genesis-root');

        if (!is_string($genesisUriHash) || $genesisUriHash === '') {
            fwrite(STDERR, "Request is missing genesis.uri_hash (required for createInstance)\n");
            return 1;
        }
        $this->assertBytes32($genesisUriHash, 'genesis-uri-hash');

        if (is_string($genesisPolicyHash) && $genesisPolicyHash !== '' && is_array($policy)) {
            $computed = $this->computeSha256Bytes32($policy);
            if (!hash_equals(strtolower($computed), strtolower($genesisPolicyHash))) {
                fwrite(STDERR, "Request is inconsistent: genesis.policy_hash does not match policy document hash.\n");
                fwrite(STDERR, "  computed: {$computed}\n");
                fwrite(STDERR, "  genesis:  {$genesisPolicyHash}\n");
                return 1;
            }
        }

        if (!is_string($genesisPolicyHash) || $genesisPolicyHash === '') {
            $mode = 'full';
            $maxStaleSec = 180;
            $policyVersion = 3;
            $enforcement = 'strict';

            if (is_array($trust)) {
                $modeRaw = $trust['mode'] ?? null;
                if (is_string($modeRaw) && $modeRaw !== '') {
                    $mode = strtolower(trim($modeRaw));
                }

                $maxRaw = $trust['max_stale_sec'] ?? null;
                if (is_int($maxRaw)) {
                    $maxStaleSec = $maxRaw;
                } elseif (is_string($maxRaw) && trim($maxRaw) !== '' && ctype_digit(trim($maxRaw))) {
                    $maxStaleSec = (int) trim($maxRaw);
                }
            }

            if (is_array($trust)) {
                $pvRaw = $trust['policy_version'] ?? null;
                if (is_int($pvRaw)) {
                    $policyVersion = $pvRaw;
                } elseif (is_string($pvRaw) && trim($pvRaw) !== '' && ctype_digit(trim($pvRaw))) {
                    $policyVersion = (int) trim($pvRaw);
                }

                $enfRaw = $trust['enforcement'] ?? null;
                if (is_string($enfRaw) && trim($enfRaw) !== '') {
                    $enforcement = strtolower(trim($enfRaw));
                }
            }

            $policyDoc = is_array($policy)
                ? $policy
                : $this->buildTrustPolicyDoc($policyVersion, $mode, $maxStaleSec, $enforcement);
            $genesisPolicyHash = $this->computeSha256Bytes32($policyDoc);
        }
        $this->assertBytes32($genesisPolicyHash, 'genesis-policy-hash');

        $chainId = 4207;
        if (is_array($chain)) {
            $cid = $chain['chain_id'] ?? null;
            if (is_int($cid)) {
                $chainId = $cid;
            } elseif (is_string($cid) && trim($cid) !== '' && ctype_digit(trim($cid))) {
                $chainId = (int) trim($cid);
            }
        }

        $data = $this->buildCreateInstanceCalldata(
            $rootAuthority,
            $upgradeAuthority,
            $emergencyAuthority,
            $genesisRoot,
            $genesisUriHash,
            $genesisPolicyHash
        );

        $dataSha256 = $this->sha256HexData($data);

        $tx = [
            'chain_id' => $chainId,
            'to' => strtolower($factory),
            'value' => '0x0',
            'data' => $data,
            'data_sha256' => $dataSha256,
            'method' => 'createInstance(address,address,address,bytes32,bytes32,bytes32)',
            'args' => [
                'root_authority' => strtolower($rootAuthority),
                'upgrade_authority' => strtolower($upgradeAuthority),
                'emergency_authority' => strtolower($emergencyAuthority),
                'genesis_root' => strtolower($genesisRoot),
                'genesis_uri_hash' => strtolower($genesisUriHash),
                'genesis_policy_hash' => strtolower($genesisPolicyHash),
            ],
            'safe' => [
                'operation' => 'CALL',
                'note' => 'For Safe: set (to,value,data,operation) to these values and confirm with the required threshold.',
            ],
        ];

        $outJson = json_encode($tx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($outJson)) {
            fwrite(STDERR, "Unable to encode JSON\n");
            return 1;
        }

        if ($out !== null) {
            try {
                $this->writeSecureJsonFile($out, $outJson);
            } catch (\Throwable $e) {
                fwrite(STDERR, $e->getMessage() . PHP_EOL);
                return 2;
            }
        }

        if ($json || $out === null) {
            echo $outJson . PHP_EOL;
        } else {
            echo "Wrote: {$out}\n";
        }

        return 0;
    }

    /**
     * Generate InstanceController.setAttestation calldata (JSON).
     *
     * @param string[] $args
     */
    private function runTrustTxControllerSetAttestation(array $args): int
    {
        [$json, $args] = $this->consumeFlag($args, '--json');
        [$runtimeConfigPath, $args] = self::consumeRuntimeConfigPath($args);

        $out = null;
        $chainId = null;
        $controller = null;
        $key = null;
        $value = null;

        foreach ($args as $arg) {
            $arg = (string) $arg;

            if ($arg === '' || $arg === '1') {
                continue;
            }

            if (str_starts_with($arg, '--out=')) {
                $out = trim(substr($arg, 6)) ?: null;
                continue;
            }
            if (str_starts_with($arg, '--chain-id=')) {
                $chainId = (int) trim(substr($arg, 11));
                continue;
            }
            if (str_starts_with($arg, '--controller=')) {
                $controller = trim(substr($arg, 13)) ?: null;
                continue;
            }
            if (str_starts_with($arg, '--key=')) {
                $key = trim(substr($arg, 6)) ?: null;
                continue;
            }
            if (str_starts_with($arg, '--value=')) {
                $value = trim(substr($arg, 8)) ?: null;
                continue;
            }

            if ($arg === '--out' || $arg === '--chain-id' || $arg === '--controller' || $arg === '--key' || $arg === '--value') {
                fwrite(STDERR, "Use --out=, --chain-id=, --controller=, --key=, --value=\n");
                return 1;
            }

            fwrite(STDERR, "Unknown argument: {$arg}\n");
            return 1;
        }

        [$chainId, $controller] = $this->fillTrustChainDefaultsFromRuntimeConfig($chainId, $controller, $runtimeConfigPath);

        if ($chainId === null || $chainId <= 0) {
            fwrite(STDERR, "Missing/invalid --chain-id (expected > 0)\n");
            return 1;
        }
        if ($controller === null) {
            fwrite(STDERR, "Missing --controller (or provide --config with trust.web3.contracts.instance_controller)\n");
            return 1;
        }
        if ($key === null || $value === null) {
            fwrite(STDERR, "Usage: blackcat trust tx:controller-set-attestation --controller=0x... --key=0x... --value=0x... [--chain-id=4207] [--out=FILE] [--json]\n");
            return 1;
        }

        $this->assertEvmAddress($controller, 'controller');
        $this->assertBytes32($key, 'key');
        $this->assertBytes32($value, 'value');

        $data = $this->buildControllerSetAttestationCalldata($key, $value);

        $tx = [
            'chain_id' => $chainId,
            'to' => strtolower($controller),
            'value' => '0x0',
            'data' => $data,
            'data_sha256' => $this->sha256HexData($data),
            'method' => 'setAttestation(bytes32,bytes32)',
            'args' => [
                'key' => strtolower($key),
                'value' => strtolower($value),
            ],
            'note' => 'Must be executed by rootAuthority (EOA/Safe) of the InstanceController.',
            'safe' => [
                'operation' => 'CALL',
                'note' => 'For Safe: set (to,value,data,operation) to these values and confirm with the required threshold.',
            ],
        ];

        $outJson = json_encode($tx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($outJson)) {
            fwrite(STDERR, "Unable to encode JSON\n");
            return 1;
        }

        if ($out !== null) {
            try {
                $this->writeSecureJsonFile($out, $outJson);
            } catch (\Throwable $e) {
                fwrite(STDERR, $e->getMessage() . PHP_EOL);
                return 2;
            }
        }

        if ($json || $out === null) {
            echo $outJson . PHP_EOL;
        } else {
            echo "Wrote: {$out}\n";
        }

        return 0;
    }

    /**
     * Generate InstanceController.lockAttestationKey calldata (JSON).
     *
     * @param string[] $args
     */
    private function runTrustTxControllerLockAttestation(array $args): int
    {
        [$json, $args] = $this->consumeFlag($args, '--json');
        [$runtimeConfigPath, $args] = self::consumeRuntimeConfigPath($args);

        $out = null;
        $chainId = null;
        $controller = null;
        $key = null;

        foreach ($args as $arg) {
            $arg = (string) $arg;

            if ($arg === '' || $arg === '1') {
                continue;
            }

            if (str_starts_with($arg, '--out=')) {
                $out = trim(substr($arg, 6)) ?: null;
                continue;
            }
            if (str_starts_with($arg, '--chain-id=')) {
                $chainId = (int) trim(substr($arg, 11));
                continue;
            }
            if (str_starts_with($arg, '--controller=')) {
                $controller = trim(substr($arg, 13)) ?: null;
                continue;
            }
            if (str_starts_with($arg, '--key=')) {
                $key = trim(substr($arg, 6)) ?: null;
                continue;
            }

            if ($arg === '--out' || $arg === '--chain-id' || $arg === '--controller' || $arg === '--key') {
                fwrite(STDERR, "Use --out=, --chain-id=, --controller=, --key=\n");
                return 1;
            }

            fwrite(STDERR, "Unknown argument: {$arg}\n");
            return 1;
        }

        [$chainId, $controller] = $this->fillTrustChainDefaultsFromRuntimeConfig($chainId, $controller, $runtimeConfigPath);

        if ($chainId === null || $chainId <= 0) {
            fwrite(STDERR, "Missing/invalid --chain-id (expected > 0)\n");
            return 1;
        }
        if ($controller === null) {
            fwrite(STDERR, "Missing --controller (or provide --config with trust.web3.contracts.instance_controller)\n");
            return 1;
        }
        if ($key === null) {
            fwrite(STDERR, "Usage: blackcat trust tx:controller-lock-attestation --controller=0x... --key=0x... [--chain-id=4207] [--out=FILE] [--json]\n");
            return 1;
        }

        $this->assertEvmAddress($controller, 'controller');
        $this->assertBytes32($key, 'key');

        $data = $this->buildControllerLockAttestationKeyCalldata($key);

        $tx = [
            'chain_id' => $chainId,
            'to' => strtolower($controller),
            'value' => '0x0',
            'data' => $data,
            'data_sha256' => $this->sha256HexData($data),
            'method' => 'lockAttestationKey(bytes32)',
            'args' => [
                'key' => strtolower($key),
            ],
            'note' => 'Must be executed by rootAuthority (EOA/Safe) of the InstanceController.',
            'safe' => [
                'operation' => 'CALL',
                'note' => 'For Safe: set (to,value,data,operation) to these values and confirm with the required threshold.',
            ],
        ];

        $outJson = json_encode($tx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($outJson)) {
            fwrite(STDERR, "Unable to encode JSON\n");
            return 1;
        }

        if ($out !== null) {
            try {
                $this->writeSecureJsonFile($out, $outJson);
            } catch (\Throwable $e) {
                fwrite(STDERR, $e->getMessage() . PHP_EOL);
                return 2;
            }
        }

        if ($json || $out === null) {
            echo $outJson . PHP_EOL;
        } else {
            echo "Wrote: {$out}\n";
        }

        return 0;
    }

    /**
     * Convenience: compute runtime-config attestation (policy v3) and generate InstanceController.setAttestationAndLock calldata (JSON).
     *
     * @param string[] $args
     */
    private function runTrustTxControllerAttestRuntimeConfig(array $args): int
    {
        [$json, $args] = $this->consumeFlag($args, '--json');
        [$runtimeConfigPath, $args] = self::consumeRuntimeConfigPath($args);

        $out = null;
        $chainId = null;
        $controller = null;

        foreach ($args as $arg) {
            $arg = (string) $arg;

            if ($arg === '' || $arg === '1') {
                continue;
            }

            if (str_starts_with($arg, '--out=')) {
                $out = trim(substr($arg, 6)) ?: null;
                continue;
            }
            if (str_starts_with($arg, '--chain-id=')) {
                $chainId = (int) trim(substr($arg, 11));
                continue;
            }
            if (str_starts_with($arg, '--controller=')) {
                $controller = trim(substr($arg, 13)) ?: null;
                continue;
            }

            if ($arg === '--out' || $arg === '--chain-id' || $arg === '--controller') {
                fwrite(STDERR, "Use --out=, --chain-id=, --controller= (and --config=FILE for runtime config)\n");
                return 1;
            }

            fwrite(STDERR, "Unknown argument: {$arg}\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        try {
            $repo = $runtimeConfigPath !== null
                ? \BlackCat\Config\Runtime\ConfigRepository::fromJsonFile($runtimeConfigPath)
                : \BlackCat\Config\Runtime\ConfigBootstrap::loadFirstAvailableJsonFile();
        } catch (\Throwable $e) {
            if ($json) {
                echo json_encode(['status' => 'fail', 'message' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                return 2;
            }
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 2;
        }

        $data = $repo->toArray();
        $key = \BlackCat\Config\Security\KernelAttestations::runtimeConfigAttestationKeyV1();
        $value = \BlackCat\Config\Security\KernelAttestations::runtimeConfigAttestationValueV1($data);

        if ($chainId === null) {
            $cid = $repo->get('trust.web3.chain_id');
            if (is_int($cid)) {
                $chainId = $cid;
            } elseif (is_string($cid) && trim($cid) !== '' && ctype_digit(trim($cid))) {
                $chainId = (int) trim($cid);
            }
        }

        if ($controller === null) {
            $controllerRaw = $repo->get('trust.web3.contracts.instance_controller');
            if (is_string($controllerRaw) && trim($controllerRaw) !== '') {
                $controller = trim($controllerRaw);
            }
        }

        if ($chainId === null || $chainId <= 0) {
            fwrite(STDERR, "Missing/invalid chain_id (provide --chain-id or set trust.web3.chain_id in runtime config)\n");
            return 1;
        }
        if ($controller === null) {
            fwrite(STDERR, "Missing controller (provide --controller or set trust.web3.contracts.instance_controller in runtime config)\n");
            return 1;
        }

        $this->assertEvmAddress($controller, 'controller');
        $this->assertBytes32($key, 'key');
        $this->assertBytes32($value, 'value');

        $calldata = $this->buildControllerSetAttestationAndLockCalldata($key, $value);

        $tx = [
            'chain_id' => $chainId,
            'to' => strtolower($controller),
            'value' => '0x0',
            'data' => $calldata,
            'data_sha256' => $this->sha256HexData($calldata),
            'method' => 'setAttestationAndLock(bytes32,bytes32)',
            'args' => [
                'key' => strtolower($key),
                'value' => strtolower($value),
            ],
            'attestation' => [
                'source_path' => $repo->sourcePath(),
                'description' => 'canonical_sha256(runtime_config_json) commitment (policy v3)',
            ],
            'note' => 'Must be executed by rootAuthority (EOA/Safe). After mining, the TrustKernel should verify attestation + lock automatically.',
            'safe' => [
                'operation' => 'CALL',
                'note' => 'For Safe: set (to,value,data,operation) to these values and confirm with the required threshold.',
            ],
        ];

        $outJson = json_encode($tx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($outJson)) {
            fwrite(STDERR, "Unable to encode JSON\n");
            return 1;
        }

        if ($out !== null) {
            try {
                $this->writeSecureJsonFile($out, $outJson);
            } catch (\Throwable $e) {
                fwrite(STDERR, $e->getMessage() . PHP_EOL);
                return 2;
            }
        }

        if ($json || $out === null) {
            echo $outJson . PHP_EOL;
        } else {
            echo "Wrote: {$out}\n";
        }

        return 0;
    }

    /**
     * @return array{0:?int,1:?string}
     */
    private function fillTrustChainDefaultsFromRuntimeConfig(?int $chainId, ?string $controller, ?string $runtimeConfigPath): array
    {
        if (($chainId !== null && $controller !== null) || !$this->ensureBlackcatConfigAvailable()) {
            return [$chainId, $controller];
        }

        try {
            $this->initRuntimeConfig($runtimeConfigPath);
        } catch (\Throwable) {
            return [$chainId, $controller];
        }

        if (!\BlackCat\Config\Runtime\Config::isInitialized()) {
            return [$chainId, $controller];
        }

        $repo = \BlackCat\Config\Runtime\Config::repo();

        if ($chainId === null) {
            try {
                $chainId = $repo->requireInt('trust.web3.chain_id');
            } catch (\Throwable) {
                $chainId = null;
            }
        }

        if ($controller === null) {
            $raw = $repo->get('trust.web3.contracts.instance_controller');
            if (is_string($raw) && trim($raw) !== '') {
                $controller = trim($raw);
            }
        }

        return [$chainId, $controller];
    }

    private function buildControllerSetAttestationCalldata(string $key, string $value): string
    {
        $this->assertBytes32($key, 'key');
        $this->assertBytes32($value, 'value');

        $data = self::ABI_SELECTOR_SET_ATTESTATION
            . $this->abiEncodeBytes32Word($key)
            . $this->abiEncodeBytes32Word($value);

        return '0x' . strtolower($data);
    }

    private function buildControllerSetAttestationAndLockCalldata(string $key, string $value): string
    {
        $this->assertBytes32($key, 'key');
        $this->assertBytes32($value, 'value');

        $data = self::ABI_SELECTOR_SET_ATTESTATION_AND_LOCK
            . $this->abiEncodeBytes32Word($key)
            . $this->abiEncodeBytes32Word($value);

        return '0x' . strtolower($data);
    }

    private function buildControllerLockAttestationKeyCalldata(string $key): string
    {
        $this->assertBytes32($key, 'key');

        $data = self::ABI_SELECTOR_LOCK_ATTESTATION_KEY
            . $this->abiEncodeBytes32Word($key);

        return '0x' . strtolower($data);
    }

    /**
     * @param string[] $args
     */
    private function runTrustStatus(array $args): int
    {
        [$json, $remaining] = $this->consumeFlag($args, '--json');
        [$runtimeConfigPath, $remaining] = self::consumeRuntimeConfigPath($remaining);
        if ($remaining !== []) {
            fwrite(STDERR, "trust status does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        try {
            if (is_string($runtimeConfigPath) && $runtimeConfigPath !== '') {
                \BlackCat\Config\Runtime\Config::initFromJsonFileIfNeeded($runtimeConfigPath);
            } else {
                $repo = \BlackCat\Config\Runtime\ConfigBootstrap::tryLoadFirstAvailableJsonFile();
                if ($repo !== null) {
                    \BlackCat\Config\Runtime\Config::initIfNeeded($repo);
                }
            }
        } catch (\Throwable $e) {
            $payload = [
                'status' => 'fail',
                'message' => $e->getMessage(),
            ];
            if ($json) {
                echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
            } else {
                fwrite(STDERR, $payload['message'] . PHP_EOL);
            }
            return 2;
        }

        if (!\BlackCat\Config\Runtime\Config::isInitialized()) {
            $payload = [
                'status' => 'missing',
                'message' => 'No usable runtime config file found. Run: blackcat config runtime init',
            ];
            if ($json) {
                echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
            } else {
                fwrite(STDERR, $payload['message'] . PHP_EOL);
            }
            return 0;
        }

        $repo = \BlackCat\Config\Runtime\Config::repo();

        $configured = $repo->get('trust.web3') !== null;
        $valid = false;
        $error = null;

        if ($configured) {
            try {
                \BlackCat\Config\Runtime\RuntimeConfigValidator::assertTrustKernelWeb3Config($repo);
                $valid = true;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $web3 = $repo->get('trust.web3');
        $chainId = is_array($web3) ? ($web3['chain_id'] ?? null) : null;
        $mode = is_array($web3) ? ($web3['mode'] ?? null) : null;

        $payload = [
            'status' => $configured ? ($valid ? 'ok' : 'fail') : 'missing',
            'configured' => $configured,
            'chain_id' => $chainId,
            'mode' => $mode,
            'message' => $error,
        ];

        if ($json) {
            echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
        } else {
            echo "Trust kernel\n";
            echo "  status: " . $payload['status'] . "\n";
            if ($payload['chain_id'] !== null) {
                echo "  chain_id: " . (string) $payload['chain_id'] . "\n";
            }
            if (is_string($payload['mode']) && $payload['mode'] !== '') {
                echo "  mode: " . $payload['mode'] . "\n";
            }
            if (is_string($payload['message']) && $payload['message'] !== '') {
                echo "  error: " . $payload['message'] . "\n";
            }
        }
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runTrustVerify(array $args): int
    {
        [$json, $remaining] = $this->consumeFlag($args, '--json');
        [$runtimeConfigPath, $remaining] = self::consumeRuntimeConfigPath($remaining);
        if ($remaining !== []) {
            fwrite(STDERR, "trust verify does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        try {
            $this->initRuntimeConfig($runtimeConfigPath);
        } catch (\Throwable $e) {
            $payload = ['status' => 'fail', 'message' => $e->getMessage()];
            if ($json) {
                echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
            } else {
                fwrite(STDERR, $payload['message'] . PHP_EOL);
            }
            return 2;
        }

        if (!\BlackCat\Config\Runtime\Config::isInitialized()) {
            $payload = [
                'status' => 'missing',
                'message' => 'No usable runtime config file found. Run: blackcat config runtime init',
            ];
            if ($json) {
                echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
            } else {
                fwrite(STDERR, $payload['message'] . PHP_EOL);
            }
            return 2;
        }

        $repo = \BlackCat\Config\Runtime\Config::repo();

        try {
            \BlackCat\Config\Runtime\RuntimeConfigValidator::assertTrustKernelWeb3Config($repo);
        } catch (\Throwable $e) {
            $payload = ['status' => 'fail', 'message' => $e->getMessage()];
            if ($json) {
                echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
            } else {
                fwrite(STDERR, $payload['message'] . PHP_EOL);
            }
            return 2;
        }

        $web3 = $repo->get('trust.web3');
        if (!is_array($web3)) {
            $payload = ['status' => 'fail', 'message' => 'trust.web3 is not configured.'];
            if ($json) {
                echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
            } else {
                fwrite(STDERR, $payload['message'] . PHP_EOL);
            }
            return 2;
        }

        $expectedChainId = $repo->requireInt('trust.web3.chain_id');
        $endpoints = $repo->get('trust.web3.rpc_endpoints');
        if (!is_array($endpoints)) {
            $payload = ['status' => 'fail', 'message' => 'trust.web3.rpc_endpoints is not a list.'];
            if ($json) {
                echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
            } else {
                fwrite(STDERR, $payload['message'] . PHP_EOL);
            }
            return 2;
        }

        /** @var list<string> $rpcEndpoints */
        $rpcEndpoints = [];
        foreach ($endpoints as $ep) {
            if (!is_string($ep)) {
                continue;
            }
            $ep = trim($ep);
            if ($ep === '') {
                continue;
            }
            $rpcEndpoints[] = $ep;
        }
        $rpcEndpoints = array_values(array_unique($rpcEndpoints));

        $quorumRaw = $repo->get('trust.web3.rpc_quorum', 1);
        $rpcQuorum = is_int($quorumRaw) ? $quorumRaw : (int) $quorumRaw;
        $rpcQuorum = max(1, $rpcQuorum);

        $controller = $repo->requireString('trust.web3.contracts.instance_controller');
        $this->assertEvmAddress($controller, 'instance-controller');

        $timeout = 2.5;

        $chainCheck = $this->rpcQuorumChainId($rpcEndpoints, $expectedChainId, $rpcQuorum, $timeout);
        $goodEndpoints = $chainCheck['matching_endpoints'];

        $status = $chainCheck['ok'] ? 'ok' : 'fail';
        $failures = [];

        if (!$chainCheck['ok']) {
            $failures[] = 'rpc.chain_id_quorum_failed';
        }

        $codeCheck = null;
        if ($chainCheck['ok']) {
            $codeCheck = $this->rpcQuorumGetCode($goodEndpoints, $rpcQuorum, $controller, $timeout);
            if (!$codeCheck['ok']) {
                $status = 'fail';
                $failures[] = 'rpc.contract_code_quorum_failed';
            }
        }

        $paused = null;
        $activeRoot = null;
        $activeUriHash = null;
        $activePolicyHash = null;
        $authorities = null;

        if ($chainCheck['ok'] && $codeCheck !== null && $codeCheck['ok']) {
            $pausedCall = $this->rpcQuorumEthCall($goodEndpoints, $rpcQuorum, $controller, '0x' . self::ABI_SELECTOR_PAUSED, $timeout);
            if (!$pausedCall['ok']) {
                $status = 'fail';
                $failures[] = 'rpc.paused_call_quorum_failed';
            } else {
                $paused = $this->decodeBoolWord($pausedCall['result']);
                if ($paused) {
                    $status = 'fail';
                    $failures[] = 'controller.paused';
                }
            }

            $rootCall = $this->rpcQuorumEthCall($goodEndpoints, $rpcQuorum, $controller, '0x' . self::ABI_SELECTOR_ACTIVE_ROOT, $timeout);
            if (!$rootCall['ok']) {
                $status = 'fail';
                $failures[] = 'rpc.active_root_call_quorum_failed';
            } else {
                $activeRoot = $this->normalizeWordHex($rootCall['result']);
                if (strtolower($activeRoot) === '0x' . str_repeat('0', 64)) {
                    $status = 'fail';
                    $failures[] = 'controller.active_root_zero';
                }
            }

            $uriCall = $this->rpcQuorumEthCall($goodEndpoints, $rpcQuorum, $controller, '0x' . self::ABI_SELECTOR_ACTIVE_URI_HASH, $timeout);
            if ($uriCall['ok']) {
                $activeUriHash = $this->normalizeWordHex($uriCall['result']);
            }

            $policyCall = $this->rpcQuorumEthCall($goodEndpoints, $rpcQuorum, $controller, '0x' . self::ABI_SELECTOR_ACTIVE_POLICY_HASH, $timeout);
            if ($policyCall['ok']) {
                $activePolicyHash = $this->normalizeWordHex($policyCall['result']);
            }

            $rootAuth = $this->rpcQuorumEthCall($goodEndpoints, $rpcQuorum, $controller, '0x' . self::ABI_SELECTOR_ROOT_AUTHORITY, $timeout);
            $upAuth = $this->rpcQuorumEthCall($goodEndpoints, $rpcQuorum, $controller, '0x' . self::ABI_SELECTOR_UPGRADE_AUTHORITY, $timeout);
            $emAuth = $this->rpcQuorumEthCall($goodEndpoints, $rpcQuorum, $controller, '0x' . self::ABI_SELECTOR_EMERGENCY_AUTHORITY, $timeout);

            if (!$rootAuth['ok'] || !$upAuth['ok'] || !$emAuth['ok']) {
                $status = 'fail';
                $failures[] = 'rpc.authorities_call_quorum_failed';
            } else {
                $authorities = [
                    'root_authority' => $this->decodeAddressWord($rootAuth['result']),
                    'upgrade_authority' => $this->decodeAddressWord($upAuth['result']),
                    'emergency_authority' => $this->decodeAddressWord($emAuth['result']),
                ];
            }
        }

        $payload = [
            'status' => $status,
            'failures' => $failures,
            'chain' => $chainCheck,
            'contract' => [
                'instance_controller' => strtolower($controller),
                'code' => $codeCheck,
                'paused' => $paused,
                'active_root' => $activeRoot,
                'active_uri_hash' => $activeUriHash,
                'active_policy_hash' => $activePolicyHash,
                'authorities' => $authorities,
            ],
        ];

        if ($json) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } else {
            echo "Trust verify\n";
            echo "  status: {$status}\n";
            if ($failures !== []) {
                echo "  failures:\n";
                foreach ($failures as $f) {
                    echo "    - {$f}\n";
                }
            }
        }

        return $status === 'ok' ? 0 : 2;
    }

    private function initRuntimeConfig(?string $runtimeConfigPath): void
    {
        if (is_string($runtimeConfigPath) && $runtimeConfigPath !== '') {
            \BlackCat\Config\Runtime\Config::initFromJsonFileIfNeeded($runtimeConfigPath);
            return;
        }

        $repo = \BlackCat\Config\Runtime\ConfigBootstrap::tryLoadFirstAvailableJsonFile();
        if ($repo !== null) {
            \BlackCat\Config\Runtime\Config::initIfNeeded($repo);
        }
    }

    /**
     * @param list<string> $endpoints
     * @return array{
     *   ok:bool,
     *   expected_chain_id:int,
     *   quorum:int,
     *   matching_endpoints:list<string>,
     *   results:list<array{endpoint:string,status:string,chain_id:?int,error:?string}>
     * }
     */
    private function rpcQuorumChainId(array $endpoints, int $expectedChainId, int $quorum, float $timeoutSeconds): array
    {
        $rows = [];
        $matching = [];

        foreach ($endpoints as $endpoint) {
            $endpoint = trim($endpoint);
            if ($endpoint === '') {
                continue;
            }

            if (!$this->isSupportedHttpEndpoint($endpoint)) {
                $rows[] = ['endpoint' => $endpoint, 'status' => 'skip', 'chain_id' => null, 'error' => 'Unsupported RPC endpoint scheme (https/http only).'];
                continue;
            }

            try {
                $result = $this->rpcCall($endpoint, 'eth_chainId', [], $timeoutSeconds);
                if (!is_string($result)) {
                    throw new \RuntimeException('Invalid JSON-RPC result type (expected string).');
                }
                $chainId = $this->hexToInt($result, 'eth_chainId');
                $rows[] = ['endpoint' => $endpoint, 'status' => 'ok', 'chain_id' => $chainId, 'error' => null];
                if ($chainId === $expectedChainId) {
                    $matching[] = $endpoint;
                }
            } catch (\Throwable $e) {
                $rows[] = ['endpoint' => $endpoint, 'status' => 'fail', 'chain_id' => null, 'error' => $e->getMessage()];
            }
        }

        return [
            'ok' => count($matching) >= $quorum,
            'expected_chain_id' => $expectedChainId,
            'quorum' => $quorum,
            'matching_endpoints' => array_values($matching),
            'results' => $rows,
        ];
    }

    /**
     * @param list<string> $endpoints
     * @return array{
     *   ok:bool,
     *   quorum:int,
     *   selected:?string,
     *   code_sha256:?string,
     *   code_bytes:?int,
     *   results:list<array{endpoint:string,status:string,code_sha256:?string,code_bytes:?int,error:?string}>
     * }
     */
    private function rpcQuorumGetCode(array $endpoints, int $quorum, string $address, float $timeoutSeconds): array
    {
        $rows = [];
        $counts = [];
        $endpointByHash = [];

        foreach ($endpoints as $endpoint) {
            if (!$this->isSupportedHttpEndpoint($endpoint)) {
                $rows[] = ['endpoint' => $endpoint, 'status' => 'skip', 'code_sha256' => null, 'code_bytes' => null, 'error' => 'Unsupported RPC endpoint scheme.'];
                continue;
            }

            try {
                $result = $this->rpcCall($endpoint, 'eth_getCode', [$address, 'latest'], $timeoutSeconds);
                if (!is_string($result)) {
                    throw new \RuntimeException('Invalid eth_getCode result type (expected string).');
                }
                $hex = $this->normalizeHex($result);
                $raw = substr($hex, 2);
                if ($raw === '' || $raw === '0') {
                    $raw = '';
                }
                $bytes = $raw !== '' ? (int) (strlen($raw) / 2) : 0;
                if ($bytes <= 0) {
                    throw new \RuntimeException('eth_getCode returned empty code (address has no contract).');
                }

                $sha = hash('sha256', hex2bin($raw) ?: '');
                $rows[] = ['endpoint' => $endpoint, 'status' => 'ok', 'code_sha256' => $sha, 'code_bytes' => $bytes, 'error' => null];
                $counts[$sha] = ($counts[$sha] ?? 0) + 1;
                $endpointByHash[$sha] ??= $endpoint;
            } catch (\Throwable $e) {
                $rows[] = ['endpoint' => $endpoint, 'status' => 'fail', 'code_sha256' => null, 'code_bytes' => null, 'error' => $e->getMessage()];
            }
        }

        $selectedSha = null;
        foreach ($counts as $sha => $count) {
            if ($count >= $quorum) {
                $selectedSha = (string) $sha;
                break;
            }
        }

        $selectedEndpoint = $selectedSha !== null ? ($endpointByHash[$selectedSha] ?? null) : null;
        $selectedBytes = null;
        if ($selectedSha !== null) {
            foreach ($rows as $row) {
                if ($row['code_sha256'] === $selectedSha) {
                    $selectedBytes = $row['code_bytes'];
                    break;
                }
            }
        }

        return [
            'ok' => $selectedSha !== null,
            'quorum' => $quorum,
            'selected' => $selectedEndpoint,
            'code_sha256' => $selectedSha,
            'code_bytes' => $selectedBytes,
            'results' => $rows,
        ];
    }

    /**
     * @param list<string> $endpoints
     * @return array{
     *   ok:bool,
     *   quorum:int,
     *   result:string,
     *   results:list<array{endpoint:string,status:string,result:?string,error:?string}>
     * }
     */
    private function rpcQuorumEthCall(array $endpoints, int $quorum, string $to, string $data, float $timeoutSeconds): array
    {
        $rows = [];
        $counts = [];

        foreach ($endpoints as $endpoint) {
            if (!$this->isSupportedHttpEndpoint($endpoint)) {
                $rows[] = ['endpoint' => $endpoint, 'status' => 'skip', 'result' => null, 'error' => 'Unsupported RPC endpoint scheme.'];
                continue;
            }

            try {
                $result = $this->rpcCall($endpoint, 'eth_call', [['to' => $to, 'data' => $data], 'latest'], $timeoutSeconds);
                if (!is_string($result)) {
                    throw new \RuntimeException('Invalid eth_call result type (expected string).');
                }
                $hex = $this->normalizeWordHex($result);
                $rows[] = ['endpoint' => $endpoint, 'status' => 'ok', 'result' => $hex, 'error' => null];
                $counts[$hex] = ($counts[$hex] ?? 0) + 1;
            } catch (\Throwable $e) {
                $rows[] = ['endpoint' => $endpoint, 'status' => 'fail', 'result' => null, 'error' => $e->getMessage()];
            }
        }

        $selected = null;
        foreach ($counts as $value => $count) {
            if ($count >= $quorum) {
                $selected = (string) $value;
                break;
            }
        }

        return [
            'ok' => $selected !== null,
            'quorum' => $quorum,
            'result' => $selected ?? ('0x' . str_repeat('0', 64)),
            'results' => $rows,
        ];
    }

    /**
     * @param array<int,mixed> $params
     */
    private function rpcCall(string $endpoint, string $method, array $params, float $timeoutSeconds): mixed
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => bin2hex(random_bytes(8)),
            'method' => $method,
            'params' => $params,
        ];

        $res = $this->httpPostJson($endpoint, $payload, $timeoutSeconds);
        if ($res['status'] !== 200) {
            throw new \RuntimeException("JSON-RPC HTTP {$res['status']} from {$endpoint}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($res['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid JSON-RPC response from: ' . $endpoint, 0, $e);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON-RPC response type from: ' . $endpoint);
        }

        if (isset($decoded['error'])) {
            $err = $decoded['error'];
            if (is_array($err) && isset($err['message']) && is_string($err['message'])) {
                throw new \RuntimeException('JSON-RPC error: ' . $err['message']);
            }
            throw new \RuntimeException('JSON-RPC error response received.');
        }

        return $decoded['result'] ?? null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,body:string}
     */
    private function httpPostJson(string $url, array $payload, float $timeoutSeconds): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode JSON-RPC payload.');
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'header' => "User-Agent: blackcat-cli\r\nContent-Type: application/json\r\n",
                'content' => $json,
            ],
        ]);

        /** @var list<string> $http_response_header */
        $http_response_header = [];
        $body = @file_get_contents($url, false, $ctx);
        $headers = $http_response_header;
        $status = 0;
        if ($headers !== []) {
            $first = (string) ($headers[0] ?? '');
            if (preg_match('/\\s(\\d{3})\\s/', $first, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($body === false) {
            throw new \RuntimeException('HTTP request failed: ' . $url);
        }

        return [
            'status' => $status,
            'body' => $body,
        ];
    }

    private function isSupportedHttpEndpoint(string $endpoint): bool
    {
        $parts = @parse_url($endpoint);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = $parts['scheme'] ?? null;
        if (!is_string($scheme)) {
            return false;
        }
        $scheme = strtolower($scheme);

        if ($scheme === 'https') {
            return true;
        }

        if ($scheme !== 'http') {
            return false;
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host)) {
            return false;
        }
        $host = strtolower($host);
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
    }

    private function hexToInt(string $hex, string $context): int
    {
        $hex = strtolower(trim($hex));
        if (!str_starts_with($hex, '0x')) {
            throw new \RuntimeException("Invalid hex result for {$context} (missing 0x prefix).");
        }
        $raw = substr($hex, 2);
        if ($raw === '' || preg_match('/^[0-9a-f]+$/', $raw) !== 1) {
            throw new \RuntimeException("Invalid hex result for {$context}.");
        }
        return (int) hexdec($raw);
    }

    private function normalizeHex(string $hex): string
    {
        $hex = strtolower(trim($hex));
        if (!str_starts_with($hex, '0x')) {
            throw new \RuntimeException('Invalid hex string (missing 0x prefix).');
        }
        $raw = substr($hex, 2);
        if ($raw === '') {
            return '0x';
        }
        if (preg_match('/^[0-9a-f]+$/', $raw) !== 1) {
            throw new \RuntimeException('Invalid hex string.');
        }
        if ((strlen($raw) % 2) === 1) {
            $raw = '0' . $raw;
        }
        return '0x' . $raw;
    }

    private function normalizeWordHex(string $hex): string
    {
        $hex = $this->normalizeHex($hex);
        $raw = substr($hex, 2);
        if ($raw === '') {
            $raw = '0';
        }
        if (strlen($raw) > 64) {
            throw new \RuntimeException('Unexpected ABI word length.');
        }
        $raw = str_pad($raw, 64, '0', STR_PAD_LEFT);
        return '0x' . $raw;
    }

    private function decodeBoolWord(string $hexWord): bool
    {
        $hex = $this->normalizeWordHex($hexWord);
        $raw = ltrim(substr($hex, 2), '0');
        if ($raw === '') {
            return false;
        }
        return $raw === '1';
    }

    private function decodeAddressWord(string $hexWord): string
    {
        $hex = $this->normalizeWordHex($hexWord);
        $raw = substr($hex, 2);
        $addr = substr($raw, -40);
        $out = '0x' . $addr;
        $this->assertEvmAddress($out, 'address');
        return strtolower($out);
    }

    private function buildCreateInstanceCalldata(
        string $rootAuthority,
        string $upgradeAuthority,
        string $emergencyAuthority,
        string $genesisRoot,
        string $genesisUriHash,
        string $genesisPolicyHash
    ): string {
        $this->assertEvmAddress($rootAuthority, 'root-authority');
        $this->assertEvmAddress($upgradeAuthority, 'upgrade-authority');
        $this->assertEvmAddress($emergencyAuthority, 'emergency-authority');
        $this->assertBytes32($genesisRoot, 'genesis-root');
        $this->assertBytes32($genesisUriHash, 'genesis-uri-hash');
        $this->assertBytes32($genesisPolicyHash, 'genesis-policy-hash');

        $data = self::ABI_SELECTOR_CREATE_INSTANCE
            . $this->abiEncodeAddressWord($rootAuthority)
            . $this->abiEncodeAddressWord($upgradeAuthority)
            . $this->abiEncodeAddressWord($emergencyAuthority)
            . $this->abiEncodeBytes32Word($genesisRoot)
            . $this->abiEncodeBytes32Word($genesisUriHash)
            . $this->abiEncodeBytes32Word($genesisPolicyHash);

        return '0x' . strtolower($data);
    }

    private function abiEncodeAddressWord(string $address): string
    {
        $this->assertEvmAddress($address, 'address');
        $raw = strtolower(substr($address, 2));
        return str_pad($raw, 64, '0', STR_PAD_LEFT);
    }

    private function abiEncodeBytes32Word(string $hex): string
    {
        $this->assertBytes32($hex, 'bytes32');
        return strtolower(substr($hex, 2));
    }

    private function sha256HexData(string $hexData): string
    {
        $hexData = $this->normalizeHex($hexData);
        $raw = substr($hexData, 2);
        $bin = $raw !== '' ? hex2bin($raw) : '';
        if ($bin === false) {
            throw new \RuntimeException('Invalid hex data (hex2bin failed).');
        }
        return hash('sha256', $bin);
    }

    private function readRequestJson(?string $path): string
    {
        $path = $path !== null ? trim($path) : null;

        if ($path === null || $path === '' || $path === '-') {
            $data = stream_get_contents(STDIN);
            if (!is_string($data) || trim($data) === '') {
                throw new \RuntimeException('Request input is empty (use --request=FILE or pipe JSON via stdin).');
            }
            return $data;
        }

        if (!is_file($path)) {
            throw new \RuntimeException('Request file not found: ' . $path);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read request file: ' . $path);
        }

        return $raw;
    }

    /**
     * @param list<string> $rpcEndpoints
     */
    private function applyTrustRequestOption(
        string $key,
        string $value,
        ?string &$out,
        int &$chainId,
        array &$rpcEndpoints,
        ?int &$quorum,
        string &$mode,
        int &$maxStaleSec,
        int &$policyVersion,
        string &$enforcement,
        ?string &$rootAuthority,
        ?string &$upgradeAuthority,
        ?string &$emergencyAuthority,
        ?string &$genesisRoot,
        ?string &$genesisUriHash,
        ?string &$genesisPolicyHash
    ): void {
        $value = trim($value);

        switch ($key) {
            case '--out':
                $out = $value !== '' ? $value : null;
                return;

            case '--chain-id':
                $chainId = (int) $value;
                return;

            case '--rpc':
                if ($value !== '') {
                    $rpcEndpoints[] = $value;
                }
                return;

            case '--quorum':
                $quorum = (int) $value;
                return;

            case '--mode':
                $mode = strtolower($value);
                return;

            case '--max-stale-sec':
                $maxStaleSec = (int) $value;
                return;

            case '--policy-version':
                $policyVersion = (int) $value;
                return;

            case '--enforcement':
                $enforcement = strtolower($value);
                return;

            case '--root-authority':
                $rootAuthority = $value !== '' ? $value : null;
                return;

            case '--upgrade-authority':
                $upgradeAuthority = $value !== '' ? $value : null;
                return;

            case '--emergency-authority':
                $emergencyAuthority = $value !== '' ? $value : null;
                return;

            case '--genesis-root':
                $genesisRoot = $value !== '' ? $value : null;
                return;

            case '--genesis-uri-hash':
                $genesisUriHash = $value !== '' ? $value : null;
                return;

            case '--genesis-policy-hash':
                $genesisPolicyHash = $value !== '' ? $value : null;
                return;

            default:
                throw new InvalidArgumentException('Unknown option: ' . $key);
        }
    }

    private function assertEvmAddress(string $address, string $label): void
    {
        $address = trim($address);
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            throw new InvalidArgumentException("Invalid {$label} address.");
        }
        if (strtolower($address) === '0x0000000000000000000000000000000000000000') {
            throw new InvalidArgumentException("Invalid {$label} address (zero address).");
        }
    }

    private function assertBytes32(string $value, string $label): void
    {
        $value = trim($value);
        if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $value)) {
            throw new InvalidArgumentException("Invalid {$label} (expected 0x + 32 bytes hex).");
        }
        if (strtolower($value) === '0x' . str_repeat('0', 64)) {
            throw new InvalidArgumentException("Invalid {$label} (zero hash).");
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function computeSha256Bytes32(array $data): string
    {
        $json = $this->canonicalJsonEncode($data);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode JSON for hashing.');
        }

        return '0x' . hash('sha256', $json);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildTrustPolicyDoc(int $policyVersion, string $mode, int $maxStaleSec, string $enforcement): array
    {
        $mode = strtolower(trim($mode));
        $enforcement = strtolower(trim($enforcement));

        if (!in_array($mode, ['root_uri', 'full'], true)) {
            throw new InvalidArgumentException('Invalid policy mode (expected root_uri|full).');
        }
        if ($maxStaleSec < 1 || $maxStaleSec > 86400) {
            throw new InvalidArgumentException('Invalid policy max_stale_sec (expected 1..86400).');
        }
        if (!in_array($enforcement, ['strict', 'warn'], true)) {
            throw new InvalidArgumentException('Invalid policy enforcement (expected strict|warn).');
        }

        return match ($policyVersion) {
            1 => [
                'schema_version' => 1,
                'type' => 'blackcat.trust.policy',
                'mode' => $mode,
                'max_stale_sec' => $maxStaleSec,
            ],
            2 => [
                'schema_version' => 2,
                'type' => 'blackcat.trust.policy',
                'mode' => $mode,
                'max_stale_sec' => $maxStaleSec,
                'enforcement' => $enforcement,
            ],
            3 => [
                'schema_version' => 3,
                'type' => 'blackcat.trust.policy',
                'mode' => $mode,
                'max_stale_sec' => $maxStaleSec,
                'enforcement' => $enforcement,
                'require_runtime_config_attestation' => true,
                'runtime_config_attestation_key' => $this->runtimeConfigAttestationKeyV1(),
                'runtime_config_attestation_must_be_locked' => true,
            ],
            default => throw new InvalidArgumentException('Invalid policy-version (expected 1|2|3).'),
        };
    }

    private function runtimeConfigAttestationKeyV1(): string
    {
        return '0x' . hash('sha256', 'blackcat.runtime_config.canonical_sha256.v1');
    }

    private function canonicalJsonEncode(mixed $value): string
    {
        $normalized = $this->canonicalJsonNormalize($value);

        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode canonical JSON.');
        }

        return $json;
    }

    private function canonicalJsonNormalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $out = [];
                foreach ($value as $v) {
                    $out[] = $this->canonicalJsonNormalize($v);
                }
                return $out;
            }

            $keys = array_keys($value);
            sort($keys, SORT_STRING);

            $out = [];
            foreach ($keys as $k) {
                $out[$k] = $this->canonicalJsonNormalize($value[$k]);
            }
            return $out;
        }

        if (is_object($value)) {
            throw new InvalidArgumentException('Objects are not supported in canonical JSON.');
        }

        return $value;
    }

    private function writeSecureJsonFile(string $path, string $json): void
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Invalid --out path.');
        }

        $dir = dirname($path);
        if ($dir === '' || $dir === '.' || $dir === DIRECTORY_SEPARATOR) {
            throw new InvalidArgumentException('Invalid --out path (must not be root).');
        }

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create directory: ' . $dir);
            }
            if (DIRECTORY_SEPARATOR !== '\\') {
                @chmod($dir, 0750);
            }
        }

        $tmp = $dir . DIRECTORY_SEPARATOR . '.blackcat-trust.' . bin2hex(random_bytes(8)) . '.tmp';
        $fp = @fopen($tmp, 'xb');
        if ($fp === false) {
            throw new \RuntimeException('Unable to create temp file in: ' . $dir);
        }

        try {
            $bytes = fwrite($fp, $json . "\n");
            if ($bytes === false) {
                throw new \RuntimeException('Unable to write request file.');
            }
        } finally {
            fclose($fp);
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            @chmod($tmp, 0600);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to move request file into place: ' . $path);
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            @chmod($path, 0600);
        }
    }

    /**
     * @param string[] $args
     */
    private function runObservability(array $args): int
    {
        $sub = $args[0] ?? 'help';

        $cliRoot = dirname(__DIR__);
        $libexec = $cliRoot . '/libexec';
        $script = $libexec . '/observability';

        if ($sub === 'help' || $sub === '--help' || $sub === '-h') {
            echo "observability\n";
            echo "Usage: blackcat observability <command> [args...]\n\n";
            echo "Commands:\n";
            echo "  config:print       Print runtime config snippet (JSON)\n";
            echo "  config:init        Write runtime config snippet to file\n";
            echo "  store:info         Local store paths + counts\n";
            echo "  events:tail        Print last N events\n";
            echo "  events:clear       Clear events file\n";
            echo "  metrics:snapshot   Aggregate metrics by name\n";
            echo "  metrics:tail       Print last N metric events\n";
            echo "  metrics:clear      Clear metrics file\n";
            echo "  metrics:export     Export aggregated metrics (prom/json)\n";
            echo "\nExamples:\n";
            echo "  blackcat observability events:tail --limit=25\n";
            echo "  blackcat observability metrics:snapshot\n";
            echo "  blackcat observability metrics:export prom\n";
            return 0;
        }

        if (!is_file($script)) {
            fwrite(STDERR, "observability runner not found: {$script}\n");
            return 1;
        }

        $cmd = array_merge([PHP_BINARY, $script], $args);
        return $this->runProcess($cmd);
    }

    /**
     * @param string[] $args
     */
    private function runCrypto(array $args): int
    {
        $cliRoot = dirname(__DIR__);
        $script = $cliRoot . '/libexec/crypto';

        if (!is_file($script)) {
            fwrite(STDERR, "crypto runner not found: {$script}\n");
            return 1;
        }

        $cmd = array_merge([PHP_BINARY, $script], $args);
        return $this->runProcess($cmd);
    }

    /**
     * @param string[] $args
     */
    private function runUsage(array $args): int
    {
        $cliRoot = dirname(__DIR__);
        $script = $cliRoot . '/libexec/usage';

        $sub = $args[0] ?? 'help';
        if ($sub === 'help' || $sub === '--help' || $sub === '-h' || $sub === '') {
            echo "usage\n";
            echo "Usage: blackcat usage [--config=FILE] <command> [args...]\n\n";
            echo "Commands:\n";
            echo "  ingest            Ingest one usage event (JSON)\n";
            echo "  aggregate         Revenue snapshot per component\n";
            echo "  export            Export raw events or revenue snapshot\n";
            echo "  coverage          Coverage summary (contexts/events)\n";
            echo "\nExamples:\n";
            echo "  blackcat usage ingest '{\"component_id\":\"identity-consent-card\",\"tenant_id\":\"acme\",\"count\":5}'\n";
            echo "  blackcat usage aggregate\n";
            echo "  blackcat usage export revenue > snapshot.json\n";
            return 0;
        }

        if (!is_file($script)) {
            fwrite(STDERR, "usage runner not found: {$script}\n");
            return 1;
        }

        $cmd = array_merge([PHP_BINARY, $script], $args);
        return $this->runProcess($cmd);
    }

    /**
     * @param string[] $args
     */
    private function runConfig(array $args): int
    {
        $sub = $args[0] ?? 'help';
        $rest = array_slice($args, 1);

        if ($sub === 'help' || $sub === '--help' || $sub === '-h' || $sub === '') {
            echo "config\n";
            echo "Usage: blackcat config <subcommand> [args...]\n\n";
            echo "Subcommands:\n";
            echo "  runtime paths                 Print read/write candidate paths\n";
            echo "  runtime scan                  Scan for first usable runtime config\n";
            echo "  runtime recommend             Recommend best write location\n";
            echo "  runtime template              Print runtime config templates\n";
            echo "  runtime init [--force]        Create runtime config at best location\n";
            echo "         [--path=FILE]          Force specific path\n";
            echo "         [--template=NAME]      Seed with template (trust-edgen, trust-edgen-compat)\n";
            echo "  runtime doctor                Inspect runtime config posture\n";
            echo "         [--path=FILE]          Read specific runtime config JSON file\n";
            echo "         [--strict]             Fail on warnings\n";
            echo "  runtime attestation           Compute kernel attestations\n";
            echo "         [runtime-config|http-allowed-hosts|composer-lock|php-fingerprint|image-digest]\n";
            echo "         [--path=FILE]          Runtime config path (or attestation input path)\n";
            echo "         [--json]               JSON output\n";
            echo "  profile list                  List config profiles\n";
            echo "  profile env <profile>         Print env vars for profile\n";
            echo "  profile modules <profile>     Print modules for profile\n";
            echo "  profile info <profile>        Print profile JSON\n";
            echo "  profile render-env <profile>  Render .env file\n";
            echo "  integration list <profile>    List integrations\n";
            echo "  integration check <profile>   Validate integrations\n";
            echo "  telemetry tail <profile>      Tail telemetry events\n";
            echo "  security check <profile>      Run security checklist\n";
            echo "  security scan [path]          Scan source tree for policy violations\n";
            echo "  security attack-surface [path]Scan source tree for attack-surface findings\n";
            echo "  check                         Run security+integration checks for all profiles\n";
            echo "\nExamples:\n";
            echo "  blackcat config runtime recommend\n";
            echo "  blackcat config runtime template trust-edgen --json > /tmp/config.seed.json\n";
            echo "  blackcat config runtime init --template=trust-edgen --force\n";
            echo "  blackcat config runtime doctor --strict\n";
            echo "  blackcat config runtime init --path=/etc/blackcat/config.runtime.json --force\n";
            echo "  blackcat config runtime attestation --path=/etc/blackcat/config.runtime.json\n";
            echo "  blackcat config runtime attestation http-allowed-hosts --path=/etc/blackcat/config.runtime.json --json\n";
            return 0;
        }

        $cmd = $sub;
        if ($cmd === 'runtime') {
            $action = (string) ($rest[0] ?? 'help');
            if ($action === '' || $action === 'help' || $action === '--help' || $action === '-h') {
                echo "config runtime\n";
                echo "Usage: blackcat config runtime <paths|scan|recommend|template|init|doctor|attestation> [options]\n\n";
                echo "Subcommands:\n";
                echo "  paths                 Print read/write candidate paths\n";
                echo "  scan                  Scan for first usable runtime config\n";
                echo "  recommend             Recommend best write location\n";
                echo "  template              Print runtime config templates\n";
                echo "  init [--force]        Create runtime config at best location\n";
                echo "       [--path=FILE]    Force specific path\n";
                echo "       [--template=NAME]Seed with template (trust-edgen, trust-edgen-compat)\n";
                echo "  doctor                Inspect runtime config posture\n";
                echo "       [--path=FILE]    Read specific runtime config JSON file\n";
                echo "       [--strict]       Fail on warnings\n";
                echo "  attestation           Compute kernel attestations\n";
                echo "       [--path=FILE]    Read specific runtime config JSON file\n";
                echo "       [--json]         JSON output\n";
                return 0;
            }
            $rest = array_slice($rest, 1);
            $cmd = 'runtime ' . $action;
        } elseif ($cmd === 'profile') {
            $action = (string) ($rest[0] ?? 'help');
            if ($action === '' || $action === 'help' || $action === '--help' || $action === '-h') {
                echo "config profile\n";
                echo "Usage: blackcat config profile <list|env|modules|info|render-env> [args...]\n\n";
                echo "Options:\n";
                echo "  --profiles=FILE   Profile config file (defaults to blackcat-config/config/profiles.php)\n";
                echo "  --json            JSON output (where supported)\n";
                return 0;
            }
            $rest = array_slice($rest, 1);
            $cmd = 'profile ' . $action;
        } elseif ($cmd === 'integration') {
            $action = (string) ($rest[0] ?? 'help');
            if ($action === '' || $action === 'help' || $action === '--help' || $action === '-h') {
                echo "config integration\n";
                echo "Usage: blackcat config integration <list|check> <profile> [options]\n\n";
                echo "Options:\n";
                echo "  --profiles=FILE   Profile config file (defaults to blackcat-config/config/profiles.php)\n";
                return 0;
            }
            $rest = array_slice($rest, 1);
            $cmd = 'integration ' . $action;
        } elseif ($cmd === 'telemetry') {
            $action = (string) ($rest[0] ?? 'help');
            if ($action === '' || $action === 'help' || $action === '--help' || $action === '-h') {
                echo "config telemetry\n";
                echo "Usage: blackcat config telemetry tail <profile> [lines] [options]\n\n";
                echo "Options:\n";
                echo "  --profiles=FILE   Profile config file (defaults to blackcat-config/config/profiles.php)\n";
                return 0;
            }
            $rest = array_slice($rest, 1);
            $cmd = 'telemetry ' . $action;
        } elseif ($cmd === 'security') {
            $action = (string) ($rest[0] ?? 'help');
            if ($action === '' || $action === 'help' || $action === '--help' || $action === '-h') {
                echo "config security\n";
                echo "Usage: blackcat config security <check|scan|attack-surface> [args...]\n\n";
                echo "Options:\n";
                echo "  --profiles=FILE   Profile config file (for 'check')\n";
                echo "  --path=DIR        Root directory to scan (for 'scan' and 'attack-surface')\n";
                echo "  --json            JSON output\n";
                return 0;
            }
            $rest = array_slice($rest, 1);
            $cmd = 'security ' . $action;
        }

        return match ($cmd) {
            'runtime paths' => $this->runConfigRuntimePaths($rest),
            'runtime scan' => $this->runConfigRuntimeScan($rest),
            'runtime recommend' => $this->runConfigRuntimeRecommend($rest),
            'runtime template' => $this->runConfigRuntimeTemplate($rest),
            'runtime init' => $this->runConfigRuntimeInit($rest),
            'runtime doctor' => $this->runConfigRuntimeDoctor($rest),
            'runtime attestation' => $this->runConfigRuntimeAttestation($rest),
            'profile list' => $this->runConfigProfileList($rest),
            'profile env' => $this->runConfigProfileEnv($rest),
            'profile modules' => $this->runConfigProfileModules($rest),
            'profile info' => $this->runConfigProfileInfo($rest),
            'profile render-env' => $this->runConfigProfileRenderEnv($rest),
            'integration list' => $this->runConfigIntegrationList($rest),
            'integration check' => $this->runConfigIntegrationCheck($rest),
            'telemetry tail' => $this->runConfigTelemetryTail($rest),
            'security check' => $this->runConfigSecurityCheck($rest),
            'security scan' => $this->runConfigSecurityScan($rest),
            'security attack-surface' => $this->runConfigSecurityAttackSurface($rest),
            'check' => $this->runConfigCheck($rest),
            default => $this->unknown('config ' . $sub),
        };
    }

    /**
     * @param string[] $args
     */
    private function runConfigRuntimePaths(array $args): int
    {
        [$json, $remaining] = $this->consumeFlag($args, '--json');
        if ($remaining !== []) {
            fwrite(STDERR, "config runtime paths does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        $write = \BlackCat\Config\Runtime\RuntimeConfigInstaller::defaultWritePaths();
        $read = \BlackCat\Config\Runtime\ConfigBootstrap::defaultJsonPaths();

        if ($json) {
            echo json_encode(['write_paths' => $write, 'read_paths' => $read], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return 0;
        }

        echo "Runtime config paths\n";
        echo "Write candidates:\n";
        foreach ($write as $p) {
            echo "  - {$p}\n";
        }
        echo "\nRead candidates:\n";
        foreach ($read as $p) {
            echo "  - {$p}\n";
        }
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigRuntimeScan(array $args): int
    {
        [$json, $remaining] = $this->consumeFlag($args, '--json');
        if ($remaining !== []) {
            fwrite(STDERR, "config runtime scan does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        $scan = \BlackCat\Config\Runtime\ConfigBootstrap::scanFirstAvailableJsonFile();

        $payload = [
            'selected' => $scan['selected'],
            'rejected' => $scan['rejected'],
            'paths' => $scan['paths'],
        ];

        if ($json) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return 0;
        }

        if (is_string($payload['selected']) && $payload['selected'] !== '') {
            echo "Selected runtime config:\n";
            echo "  " . $payload['selected'] . "\n";
        } else {
            echo "No usable runtime config found.\n";
        }

        if ($payload['rejected'] !== []) {
            echo "\nRejected files:\n";
            /** @var array<string,string> $rejected */
            $rejected = $payload['rejected'];
            foreach ($rejected as $path => $reason) {
                echo "  - {$path}: {$reason}\n";
            }
        }

        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigRuntimeRecommend(array $args): int
    {
        [$json, $remaining] = $this->consumeFlag($args, '--json');
        if ($remaining !== []) {
            fwrite(STDERR, "config runtime recommend does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        $rec = \BlackCat\Config\Runtime\RuntimeConfigInstaller::recommendWritePath();

        if ($json) {
            echo json_encode($rec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return 0;
        }

        $path = $rec['path'] ?? null;
        if (!is_string($path) || $path === '') {
            fwrite(STDERR, "No recommended runtime config path found.\n");
            return 2;
        }

        echo "Recommended runtime config path:\n";
        echo "  {$path}\n";
        echo "Reason:\n";
        echo "  " . $rec['reason'] . "\n";
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigRuntimeTemplate(array $args): int
    {
        [$json, $args] = $this->consumeFlag($args, '--json');

        $name = $args[0] ?? null;
        if ($name === null || $name === '' || $name === 'help' || $name === '--help' || $name === '-h') {
            echo "config runtime template\n";
            echo "Usage: blackcat config runtime template <trust-edgen|trust-edgen-compat> [--json]\n";
            return 0;
        }

        if (count($args) > 1) {
            fwrite(STDERR, "config runtime template does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        try {
            $mode = match (strtolower(trim((string) $name))) {
                'trust-edgen' => 'full',
                'trust-edgen-compat' => 'root_uri',
                default => throw new \RuntimeException('Unknown runtime template: ' . $name),
            };

            /** @var array<string,mixed> $payload */
            $payload = \BlackCat\Config\Runtime\Templates\TrustKernelEdgenTemplate::build($mode);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 2;
        }

        if ($json) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return 0;
        }

        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigRuntimeInit(array $args): int
    {
        [$json, $args] = $this->consumeFlag($args, '--json');
        [$force, $args] = $this->consumeFlag($args, '--force');

        $path = null;
        $template = null;
        $filtered = [];
        $expectPath = false;
        $expectTemplate = false;

        foreach ($args as $arg) {
            if ($expectPath) {
                $expectPath = false;
                $candidate = trim((string) $arg);
                if ($candidate === '' || $candidate === '1') {
                    fwrite(STDERR, "Invalid value for --path\n");
                    return 1;
                }
                if ($path !== null) {
                    fwrite(STDERR, "Duplicate --path option\n");
                    return 1;
                }
                $path = $candidate;
                continue;
            }

            if ($expectTemplate) {
                $expectTemplate = false;
                $candidate = trim((string) $arg);
                if ($candidate === '' || $candidate === '1') {
                    fwrite(STDERR, "Invalid value for --template\n");
                    return 1;
                }
                if ($template !== null) {
                    fwrite(STDERR, "Duplicate --template option\n");
                    return 1;
                }
                $template = $candidate;
                continue;
            }

            if ($arg === '--path') {
                $expectPath = true;
                continue;
            }

            if ($arg === '--out') {
                $expectPath = true;
                continue;
            }

            if (str_starts_with($arg, '--path=')) {
                $val = trim((string) (explode('=', $arg, 2)[1] ?? ''));
                if ($val === '' || $val === '1') {
                    fwrite(STDERR, "Invalid value for --path\n");
                    return 1;
                }
                if ($path !== null) {
                    fwrite(STDERR, "Duplicate --path option\n");
                    return 1;
                }
                $path = $val;
                continue;
            }

            if (str_starts_with($arg, '--out=')) {
                $val = trim((string) (explode('=', $arg, 2)[1] ?? ''));
                if ($val === '' || $val === '1') {
                    fwrite(STDERR, "Invalid value for --out\n");
                    return 1;
                }
                if ($path !== null) {
                    fwrite(STDERR, "Duplicate --out/--path option\n");
                    return 1;
                }
                $path = $val;
                continue;
            }

            if ($arg === '--template') {
                $expectTemplate = true;
                continue;
            }

            if (str_starts_with($arg, '--template=')) {
                $val = trim((string) (explode('=', $arg, 2)[1] ?? ''));
                if ($val === '' || $val === '1') {
                    fwrite(STDERR, "Invalid value for --template\n");
                    return 1;
                }
                if ($template !== null) {
                    fwrite(STDERR, "Duplicate --template option\n");
                    return 1;
                }
                $template = $val;
                continue;
            }

            $filtered[] = $arg;
        }

        if ($expectPath) {
            fwrite(STDERR, "Missing value for --path\n");
            return 1;
        }

        if ($expectTemplate) {
            fwrite(STDERR, "Missing value for --template\n");
            return 1;
        }

        if ($filtered !== []) {
            fwrite(STDERR, "config runtime init does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        try {
            $payload = [];
            if ($template !== null) {
                $t = strtolower(trim((string) $template));
                if ($t === 'trust-edgen') {
                    $payload = \BlackCat\Config\Runtime\Templates\TrustKernelEdgenTemplate::build('full');
                } elseif ($t === 'trust-edgen-compat') {
                    $payload = \BlackCat\Config\Runtime\Templates\TrustKernelEdgenTemplate::build('root_uri');
                } else {
                    throw new \RuntimeException('Unknown runtime template: ' . $template);
                }
            }

            $res = \BlackCat\Config\Runtime\RuntimeConfigInstaller::init($payload, $path, $force);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 2;
        }

        if ($json) {
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return 0;
        }

        echo "Runtime config initialized:\n";
        echo "  path:    " . $res['path'] . "\n";
        echo "  created: " . ($res['created'] ? 'yes' : 'no') . "\n";
        if ($res['rejected'] !== []) {
            echo "Rejected candidates:\n";
            /** @var array<string,string> $rej */
            $rej = $res['rejected'];
            foreach ($rej as $p => $reason) {
                echo "  - {$p}: {$reason}\n";
            }
        }
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigRuntimeDoctor(array $args): int
    {
        [$json, $args] = $this->consumeFlag($args, '--json');
        [$strict, $args] = $this->consumeFlag($args, '--strict');

        $path = null;
        $expectPath = false;

        foreach ($args as $arg) {
            if ($expectPath) {
                $expectPath = false;
                $candidate = trim((string) $arg);
                if ($candidate === '' || $candidate === '1') {
                    fwrite(STDERR, "Invalid value for --path\n");
                    return 1;
                }
                if ($path !== null) {
                    fwrite(STDERR, "Duplicate --path option\n");
                    return 1;
                }
                $path = $candidate;
                continue;
            }

            if ($arg === '--path') {
                $expectPath = true;
                continue;
            }

            if (str_starts_with($arg, '--path=')) {
                $val = trim((string) (explode('=', $arg, 2)[1] ?? ''));
                if ($val === '' || $val === '1') {
                    fwrite(STDERR, "Invalid value for --path\n");
                    return 1;
                }
                if ($path !== null) {
                    fwrite(STDERR, "Duplicate --path option\n");
                    return 1;
                }
                $path = $val;
                continue;
            }

            if (str_starts_with($arg, '--')) {
                fwrite(STDERR, "Unknown option: {$arg}\n");
                return 1;
            }

            if ($path === null) {
                $candidate = trim((string) $arg);
                if ($candidate !== '' && $candidate !== '1') {
                    $path = $candidate;
                    continue;
                }
            }

            fwrite(STDERR, "Unexpected argument: {$arg}\n");
            return 1;
        }

        if ($expectPath) {
            fwrite(STDERR, "Missing value for --path\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        try {
            $repo = $path !== null
                ? \BlackCat\Config\Runtime\ConfigRepository::fromJsonFile($path)
                : \BlackCat\Config\Runtime\ConfigBootstrap::loadFirstAvailableJsonFile();

            $res = \BlackCat\Config\Runtime\RuntimeDoctor::inspect($repo);
        } catch (\Throwable $e) {
            if ($json) {
                echo json_encode(['status' => 'fail', 'message' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                return 2;
            }
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 2;
        }

        $errors = $res['summary']['errors'];
        $warnings = $res['summary']['warnings'];

        if ($json) {
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            if ($errors > 0 || ($strict && $warnings > 0)) {
                return 2;
            }
            return 0;
        }

        echo "Runtime doctor\n";
        echo "  ok:         " . ($res['ok'] ? 'yes' : 'no') . "\n";
        echo "  ok_strict:  " . ($res['ok_strict'] ? 'yes' : 'no') . "\n";
        echo "  tier:       " . $res['tier'] . "\n";
        if ($res['source_path'] !== null && $res['source_path'] !== '') {
            echo "  source_path: " . $res['source_path'] . "\n";
        }
        echo "  summary:    errors={$errors} warnings={$warnings} infos=" . $res['summary']['infos'] . "\n";

        if ($res['findings'] !== []) {
            echo "\nFindings:\n";
            foreach ($res['findings'] as $finding) {
                echo "  - [" . $finding['severity'] . "] " . $finding['code'] . ' ' . $finding['message'] . "\n";
            }
        }

        if ($errors > 0 || ($strict && $warnings > 0)) {
            return 2;
        }
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigRuntimeAttestation(array $args): int
    {
        [$json, $args] = $this->consumeFlag($args, '--json');

        $type = null;
        $path = null;
        $digest = null;
        $expectPath = false;
        $expectDigest = false;

        foreach ($args as $arg) {
            if ($expectPath) {
                $expectPath = false;
                $candidate = trim((string) $arg);
                if ($candidate === '' || $candidate === '1') {
                    fwrite(STDERR, "Invalid value for --path\n");
                    return 1;
                }
                if ($path !== null) {
                    fwrite(STDERR, "Duplicate --path option\n");
                    return 1;
                }
                $path = $candidate;
                continue;
            }

            if ($expectDigest) {
                $expectDigest = false;
                $candidate = trim((string) $arg);
                if ($candidate === '' || $candidate === '1') {
                    fwrite(STDERR, "Invalid value for --digest\n");
                    return 1;
                }
                if ($digest !== null) {
                    fwrite(STDERR, "Duplicate --digest option\n");
                    return 1;
                }
                $digest = $candidate;
                continue;
            }

            if ($arg === '--path') {
                $expectPath = true;
                continue;
            }

            if (str_starts_with($arg, '--path=')) {
                $val = trim((string) (explode('=', $arg, 2)[1] ?? ''));
                if ($val === '' || $val === '1') {
                    fwrite(STDERR, "Invalid value for --path\n");
                    return 1;
                }
                if ($path !== null) {
                    fwrite(STDERR, "Duplicate --path option\n");
                    return 1;
                }
                $path = $val;
                continue;
            }

            if ($arg === '--digest') {
                $expectDigest = true;
                continue;
            }

            if (str_starts_with($arg, '--digest=')) {
                $val = trim((string) (explode('=', $arg, 2)[1] ?? ''));
                if ($val === '' || $val === '1') {
                    fwrite(STDERR, "Invalid value for --digest\n");
                    return 1;
                }
                if ($digest !== null) {
                    fwrite(STDERR, "Duplicate --digest option\n");
                    return 1;
                }
                $digest = $val;
                continue;
            }

            if (str_starts_with($arg, '--')) {
                fwrite(STDERR, "Unknown option: {$arg}\n");
                return 1;
            }

            $candidate = trim((string) $arg);
            if ($candidate === '' || $candidate === '1') {
                continue;
            }

            if ($type === null && $path === null) {
                if (in_array($candidate, ['runtime-config', 'http-allowed-hosts', 'composer-lock', 'php-fingerprint', 'image-digest'], true)) {
                    $type = $candidate;
                    continue;
                }

                if (is_file($candidate)) {
                    $path = $candidate;
                    continue;
                }

                // Treat unknown tokens as type to fail-fast with a clear message.
                $type = $candidate;
                continue;
            }

            if ($path === null) {
                $path = $candidate;
                continue;
            }

            fwrite(STDERR, "Unexpected argument: {$arg}\n");
            return 1;
        }

        if ($expectPath) {
            fwrite(STDERR, "Missing value for --path\n");
            return 1;
        }

        if ($expectDigest) {
            fwrite(STDERR, "Missing value for --digest\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        try {
            $type = $type ?? 'runtime-config';

            if ($type === 'runtime-config') {
                $repo = $path !== null
                    ? \BlackCat\Config\Runtime\ConfigRepository::fromJsonFile($path)
                    : \BlackCat\Config\Runtime\ConfigBootstrap::loadFirstAvailableJsonFile();

                $data = $repo->toArray();
                $key = \BlackCat\Config\Security\KernelAttestations::runtimeConfigAttestationKeyV1();
                $value = \BlackCat\Config\Security\KernelAttestations::runtimeConfigAttestationValueV1($data);

                $payload = [
                    'attestation' => ['key' => $key, 'value' => $value],
                    'source_path' => $repo->sourcePath(),
                ];

                if ($json) {
                    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                    return 0;
                }

                echo "Runtime config attestation (v1)\n";
                echo "  key:   {$key}\n";
                echo "  value: {$value}\n";
                if (is_string($payload['source_path']) && $payload['source_path'] !== '') {
                    echo "  source_path: " . $payload['source_path'] . "\n";
                }
                return 0;
            }

            if ($type === 'http-allowed-hosts') {
                $repo = $path !== null
                    ? \BlackCat\Config\Runtime\ConfigRepository::fromJsonFile($path)
                    : \BlackCat\Config\Runtime\ConfigBootstrap::loadFirstAvailableJsonFile();

                $raw = $repo->get('http.allowed_hosts');
                if ($raw === null || $raw === '') {
                    throw new \RuntimeException('Missing required config list: http.allowed_hosts');
                }
                if (!is_array($raw)) {
                    throw new \RuntimeException('Invalid config type for http.allowed_hosts (expected list of strings).');
                }

                $payloadRaw = \BlackCat\Config\Security\KernelAttestations::httpAllowedHostsPayloadV1($raw);
                $key = \BlackCat\Config\Security\KernelAttestations::httpAllowedHostsAttestationKeyV1();
                $value = \BlackCat\Config\Security\CanonicalJson::sha256Bytes32($payloadRaw);

                $out = [
                    'attestation' => ['key' => $key, 'value' => $value],
                    'payload' => $payloadRaw,
                    'source_path' => $repo->sourcePath(),
                ];

                if ($json) {
                    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                    return 0;
                }

                echo "HTTP allowed hosts attestation (v1)\n";
                echo "  key:   {$key}\n";
                echo "  value: {$value}\n";
                return 0;
            }

            if ($type === 'composer-lock') {
                $target = $path;

                if ($target === null) {
                    $repo = \BlackCat\Config\Runtime\ConfigBootstrap::loadFirstAvailableJsonFile();
                    $rootDirRaw = $repo->get('trust.integrity.root_dir');
                    if (!is_string($rootDirRaw) || trim($rootDirRaw) === '') {
                        throw new \RuntimeException('Unable to derive composer.lock path (missing trust.integrity.root_dir). Use --path=...');
                    }
                    $rootDir = $repo->resolvePath($rootDirRaw);
                    $target = rtrim($rootDir, "/\\") . DIRECTORY_SEPARATOR . 'composer.lock';
                }

                $policy = new \BlackCat\Config\Security\ConfigFilePolicy(
                    allowSymlinks: false,
                    allowWorldReadable: true,
                    allowGroupWritable: false,
                    allowWorldWritable: false,
                    maxBytes: 8 * 1024 * 1024,
                    checkParentDirs: true,
                    enforceOwner: false,
                );

                \BlackCat\Config\Security\SecureFile::assertSecureReadableFile($target, $policy);

                $rawLock = file_get_contents($target);
                if ($rawLock === false) {
                    throw new \RuntimeException('Unable to read composer.lock: ' . $target);
                }

                /** @var mixed $decoded */
                $decoded = json_decode($rawLock, true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('composer.lock must decode to an object/array: ' . $target);
                }

                /** @var array<string,mixed> $decoded */
                $key = \BlackCat\Config\Security\KernelAttestations::composerLockAttestationKeyV1();
                $value = \BlackCat\Config\Security\KernelAttestations::composerLockAttestationValueV1($decoded);

                $out = [
                    'attestation' => ['key' => $key, 'value' => $value],
                    'source_path' => $target,
                ];

                if ($json) {
                    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                    return 0;
                }

                echo "Composer.lock attestation (v1)\n";
                echo "  key:   {$key}\n";
                echo "  value: {$value}\n";
                echo "  source_path: {$target}\n";
                return 0;
            }

            if ($type === 'php-fingerprint') {
                $payloadV2 = \BlackCat\Config\Security\KernelAttestations::phpFingerprintPayloadV2();
                $keyV2 = \BlackCat\Config\Security\KernelAttestations::phpFingerprintAttestationKeyV2();
                $valueV2 = \BlackCat\Config\Security\KernelAttestations::phpFingerprintAttestationValueV2($payloadV2);

                $payloadV1 = \BlackCat\Config\Security\KernelAttestations::phpFingerprintPayloadV1();
                $keyV1 = \BlackCat\Config\Security\KernelAttestations::phpFingerprintAttestationKeyV1();
                $valueV1 = \BlackCat\Config\Security\KernelAttestations::phpFingerprintAttestationValueV1($payloadV1);

                $out = [
                    'attestation_v2' => ['key' => $keyV2, 'value' => $valueV2],
                    'payload_v2' => $payloadV2,
                    'attestation_v1' => ['key' => $keyV1, 'value' => $valueV1],
                    'payload_v1' => $payloadV1,
                ];

                if ($json) {
                    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                    return 0;
                }

                echo "PHP fingerprint attestation\n";
                echo "  v2.key:   {$keyV2}\n";
                echo "  v2.value: {$valueV2}\n";
                echo "  v1.key:   {$keyV1}\n";
                echo "  v1.value: {$valueV1}\n";
                return 0;
            }

            if ($type === 'image-digest') {
                $target = $path ?? '/etc/blackcat/image.digest';
                $sourcePath = null;

                if ($digest === null) {
                    $policy = \BlackCat\Config\Security\ConfigFilePolicy::publicReadable();
                    \BlackCat\Config\Security\SecureFile::assertSecureReadableFile($target, $policy);
                    $rawDigest = file_get_contents($target);
                    if ($rawDigest === false) {
                        throw new \RuntimeException('Unable to read image digest file: ' . $target);
                    }
                    $digest = $rawDigest;
                    $sourcePath = $target;
                }

                $key = \BlackCat\Config\Security\KernelAttestations::imageDigestAttestationKeyV1();
                $value = \BlackCat\Config\Security\KernelAttestations::imageDigestAttestationValueV1($digest);

                $out = [
                    'attestation' => ['key' => $key, 'value' => $value],
                    'source_path' => $sourcePath,
                ];

                if ($json) {
                    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                    return 0;
                }

                echo "Image digest attestation (v1)\n";
                echo "  key:   {$key}\n";
                echo "  value: {$value}\n";
                if (is_string($sourcePath) && $sourcePath !== '') {
                    echo "  source_path: {$sourcePath}\n";
                }
                return 0;
            }

            throw new \RuntimeException('Unknown attestation type: ' . $type);
        } catch (\Throwable $e) {
            if ($json) {
                echo json_encode(['status' => 'fail', 'message' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                return 2;
            }

            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 2;
        }
    }

    /**
     * @param string[] $args
     */
    private function runConfigProfileList(array $args): int
    {
        [$json, $args] = $this->consumeFlag($args, '--json');
        [$profilesPath, $args, $ok] = $this->consumeConfigProfilesPath($args);
        if (!$ok) {
            return 1;
        }
        if ($args !== []) {
            fwrite(STDERR, "config profile list does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        $profileConfig = $this->loadBlackcatProfileConfigOrFail($profilesPath);
        if ($profileConfig === null) {
            return 2;
        }

        if ($json) {
            echo json_encode($profileConfig->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return 0;
        }

        foreach ($profileConfig->profiles() as $profile) {
            printf(
                "- %-12s %-12s modules:%d\n",
                $profile->name(),
                $profile->environment(),
                count($profile->modules())
            );
        }
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigProfileEnv(array $args): int
    {
        [$profilesPath, $args, $ok] = $this->consumeConfigProfilesPath($args);
        if (!$ok) {
            return 1;
        }
        $profileName = $args[0] ?? null;
        if (!is_string($profileName) || $profileName === '') {
            fwrite(STDERR, "config profile env requires <profile>\n");
            return 1;
        }
        if (count($args) > 1) {
            fwrite(STDERR, "config profile env does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        $profileConfig = $this->loadBlackcatProfileConfigOrFail($profilesPath);
        if ($profileConfig === null) {
            return 2;
        }

        $profile = $this->requireConfigProfile($profileConfig, $profileName);
        if ($profile === null) {
            return 1;
        }

        foreach ($profile->env() as $key => $value) {
            printf("%s=%s\n", $key, $value);
        }
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigProfileModules(array $args): int
    {
        [$profilesPath, $args, $ok] = $this->consumeConfigProfilesPath($args);
        if (!$ok) {
            return 1;
        }
        $profileName = $args[0] ?? null;
        if (!is_string($profileName) || $profileName === '') {
            fwrite(STDERR, "config profile modules requires <profile>\n");
            return 1;
        }
        if (count($args) > 1) {
            fwrite(STDERR, "config profile modules does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        $profileConfig = $this->loadBlackcatProfileConfigOrFail($profilesPath);
        if ($profileConfig === null) {
            return 2;
        }

        $profile = $this->requireConfigProfile($profileConfig, $profileName);
        if ($profile === null) {
            return 1;
        }

        foreach ($profile->modules() as $module) {
            echo $module . PHP_EOL;
        }
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigProfileInfo(array $args): int
    {
        [$json, $args] = $this->consumeFlag($args, '--json');
        [$profilesPath, $args, $ok] = $this->consumeConfigProfilesPath($args);
        if (!$ok) {
            return 1;
        }

        $profileName = $args[0] ?? null;
        if (!is_string($profileName) || $profileName === '') {
            fwrite(STDERR, "config profile info requires <profile>\n");
            return 1;
        }
        if (count($args) > 1) {
            fwrite(STDERR, "config profile info does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        $profileConfig = $this->loadBlackcatProfileConfigOrFail($profilesPath);
        if ($profileConfig === null) {
            return 2;
        }

        $profile = $this->requireConfigProfile($profileConfig, $profileName);
        if ($profile === null) {
            return 1;
        }

        $payload = $profile->toArray();
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigProfileRenderEnv(array $args): int
    {
        [$profilesPath, $args, $ok] = $this->consumeConfigProfilesPath($args);
        if (!$ok) {
            return 1;
        }

        $profileName = $args[0] ?? null;
        if (!is_string($profileName) || $profileName === '') {
            fwrite(STDERR, "config profile render-env requires <profile>\n");
            return 1;
        }

        $target = $args[1] ?? null;
        if ($target !== null && (!is_string($target) || $target === '')) {
            fwrite(STDERR, "Invalid target path\n");
            return 1;
        }

        if (count($args) > 2) {
            fwrite(STDERR, "config profile render-env accepts at most 2 arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        $profileConfig = $this->loadBlackcatProfileConfigOrFail($profilesPath);
        if ($profileConfig === null) {
            return 2;
        }

        $profile = $this->requireConfigProfile($profileConfig, $profileName);
        if ($profile === null) {
            return 1;
        }

        $target = $target ?? ($this->config->workspaceRoot() . '/blackcat-config/var/env/' . $profile->name() . '.env');

        try {
            $renderer = new \BlackCat\Config\Env\EnvRenderer();
            $path = $renderer->render($profile, $target);
            echo "Env file generated: {$path}\n";
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 2;
        }
    }

    /**
     * @param string[] $args
     */
    private function runConfigIntegrationList(array $args): int
    {
        [$profilesPath, $args, $ok] = $this->consumeConfigProfilesPath($args);
        if (!$ok) {
            return 1;
        }
        $profileName = $args[0] ?? null;
        if (!is_string($profileName) || $profileName === '') {
            fwrite(STDERR, "config integration list requires <profile>\n");
            return 1;
        }
        if (count($args) > 1) {
            fwrite(STDERR, "config integration list does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        $profileConfig = $this->loadBlackcatProfileConfigOrFail($profilesPath);
        if ($profileConfig === null) {
            return 2;
        }

        $profile = $this->requireConfigProfile($profileConfig, $profileName);
        if ($profile === null) {
            return 1;
        }

        foreach ($profile->integrations() as $name => $path) {
            printf("%-16s %s\n", $name, $path);
        }
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigIntegrationCheck(array $args): int
    {
        [$profilesPath, $args, $ok] = $this->consumeConfigProfilesPath($args);
        if (!$ok) {
            return 1;
        }
        $profileName = $args[0] ?? null;
        if (!is_string($profileName) || $profileName === '') {
            fwrite(STDERR, "config integration check requires <profile>\n");
            return 1;
        }
        if (count($args) > 1) {
            fwrite(STDERR, "config integration check does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        $profileConfig = $this->loadBlackcatProfileConfigOrFail($profilesPath);
        if ($profileConfig === null) {
            return 2;
        }

        $profile = $this->requireConfigProfile($profileConfig, $profileName);
        if ($profile === null) {
            return 1;
        }

        $checker = new \BlackCat\Config\Integration\IntegrationChecker();
        $issues = $checker->check($profile);
        if ($issues !== []) {
            foreach ($issues as $issue) {
                fwrite(STDERR, "- {$issue}\n");
            }
            return 1;
        }

        echo "All integrations resolved for {$profile->name()}.\n";
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigTelemetryTail(array $args): int
    {
        [$profilesPath, $args, $ok] = $this->consumeConfigProfilesPath($args);
        if (!$ok) {
            return 1;
        }
        $profileName = $args[0] ?? null;
        if (!is_string($profileName) || $profileName === '') {
            fwrite(STDERR, "config telemetry tail requires <profile>\n");
            return 1;
        }

        $lines = isset($args[1]) ? max(1, (int) $args[1]) : 20;
        if (count($args) > 2) {
            fwrite(STDERR, "config telemetry tail accepts at most 2 arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        $profileConfig = $this->loadBlackcatProfileConfigOrFail($profilesPath);
        if ($profileConfig === null) {
            return 2;
        }

        $profile = $this->requireConfigProfile($profileConfig, $profileName);
        if ($profile === null) {
            return 1;
        }

        $defaultTelemetry = (string) ($profileConfig->defaults()['telemetry']['channel'] ?? 'stdout');
        $emitter = \BlackCat\Config\Telemetry\TelemetryEmitter::forChannel($profile->telemetryChannel($defaultTelemetry));
        $records = $emitter->tail($lines);
        foreach ($records as $record) {
            echo $record . PHP_EOL;
        }
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigSecurityCheck(array $args): int
    {
        [$profilesPath, $args, $ok] = $this->consumeConfigProfilesPath($args);
        if (!$ok) {
            return 1;
        }
        $profileName = $args[0] ?? null;
        if (!is_string($profileName) || $profileName === '') {
            fwrite(STDERR, "config security check requires <profile>\n");
            return 1;
        }
        if (count($args) > 1) {
            fwrite(STDERR, "config security check does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        $profileConfig = $this->loadBlackcatProfileConfigOrFail($profilesPath);
        if ($profileConfig === null) {
            return 2;
        }

        $profile = $this->requireConfigProfile($profileConfig, $profileName);
        if ($profile === null) {
            return 1;
        }

        $checklist = new \BlackCat\Config\Security\SecurityChecklist();
        $issues = $checklist->validate($profile);
        if ($issues !== []) {
            foreach ($issues as $issue) {
                fwrite(STDERR, "- {$issue}\n");
            }
            return 1;
        }

        echo "Security checklist passed for {$profile->name()}.\n";
        return 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigSecurityScan(array $args): int
    {
        [$json, $args] = $this->consumeFlag($args, '--json');

        $root = (string) (getcwd() ?: '.');
        [$root, $args, $ok] = $this->consumePathOption($args, $root);
        if (!$ok) {
            return 1;
        }
        if ($args !== []) {
            fwrite(STDERR, "config security scan does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        try {
            $res = \BlackCat\Config\Security\SourceCodePolicyScanner::scan($root);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }

        if ($json) {
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } else {
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }

        return $res['violations'] === [] ? 0 : 2;
    }

    /**
     * @param string[] $args
     */
    private function runConfigSecurityAttackSurface(array $args): int
    {
        [$json, $args] = $this->consumeFlag($args, '--json');

        $root = (string) (getcwd() ?: '.');
        [$root, $args, $ok] = $this->consumePathOption($args, $root);
        if (!$ok) {
            return 1;
        }
        if ($args !== []) {
            fwrite(STDERR, "config security attack-surface does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        try {
            $res = \BlackCat\Config\Security\AttackSurfaceScanner::scan($root);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }

        if ($json) {
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } else {
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }

        $hasErrors = false;
        foreach ($res['findings'] as $finding) {
            if ($finding['severity'] === 'error') {
                $hasErrors = true;
                break;
            }
        }

        return $hasErrors ? 2 : 0;
    }

    /**
     * @param string[] $args
     */
    private function runConfigCheck(array $args): int
    {
        [$profilesPath, $args, $ok] = $this->consumeConfigProfilesPath($args);
        if (!$ok) {
            return 1;
        }
        if ($args !== []) {
            fwrite(STDERR, "config check does not accept additional arguments\n");
            return 1;
        }

        if (!$this->ensureBlackcatConfigAvailable()) {
            return 2;
        }

        $profileConfig = $this->loadBlackcatProfileConfigOrFail($profilesPath);
        if ($profileConfig === null) {
            return 2;
        }

        $checklist = new \BlackCat\Config\Security\SecurityChecklist();
        $integrationChecker = new \BlackCat\Config\Integration\IntegrationChecker();

        $failures = [];
        foreach ($profileConfig->profiles() as $profile) {
            $securityIssues = $checklist->validate($profile);
            $integrationIssues = $integrationChecker->check($profile);
            if ($securityIssues !== [] || $integrationIssues !== []) {
                $failures[$profile->name()] = array_merge($securityIssues, $integrationIssues);
            }
        }

        if ($failures !== []) {
            foreach ($failures as $profile => $issues) {
                fwrite(STDERR, "[{$profile}]\n");
                foreach ($issues as $issue) {
                    fwrite(STDERR, "  - {$issue}\n");
                }
            }
            return 1;
        }

        echo "All profiles passed security + integration checks.\n";
        return 0;
    }

    /**
     * @param string[] $args
     * @return array{0:?string,1:string[],2:bool}
     */
    private function consumeConfigProfilesPath(array $args): array
    {
        $filtered = [];
        $path = null;
        $expect = false;

        foreach ($args as $arg) {
            if ($expect) {
                $expect = false;
                $candidate = trim((string) $arg);
                if ($candidate === '' || $candidate === '1') {
                    fwrite(STDERR, "Invalid value for --profiles\n");
                    return [null, [], false];
                }
                if ($path !== null) {
                    fwrite(STDERR, "Duplicate --profiles option\n");
                    return [null, [], false];
                }
                $path = $candidate;
                continue;
            }

            if ($arg === '--profiles') {
                $expect = true;
                continue;
            }

            if (str_starts_with($arg, '--profiles=')) {
                $val = trim((string) (explode('=', $arg, 2)[1] ?? ''));
                if ($val === '' || $val === '1') {
                    fwrite(STDERR, "Invalid value for --profiles\n");
                    return [null, [], false];
                }
                if ($path !== null) {
                    fwrite(STDERR, "Duplicate --profiles option\n");
                    return [null, [], false];
                }
                $path = $val;
                continue;
            }

            $filtered[] = $arg;
        }

        if ($expect) {
            fwrite(STDERR, "Missing value for --profiles\n");
            return [null, [], false];
        }

        return [$path, $filtered, true];
    }

    /**
     * @param string[] $args
     * @return array{0:string,1:string[],2:bool}
     */
    private function consumePathOption(array $args, string $default): array
    {
        $filtered = [];
        $root = $default;
        $expect = false;

        foreach ($args as $arg) {
            if ($expect) {
                $expect = false;
                $candidate = trim((string) $arg);
                if ($candidate === '' || $candidate === '1') {
                    fwrite(STDERR, "Invalid value for --path\n");
                    return [$root, [], false];
                }
                $root = $candidate;
                continue;
            }

            if ($arg === '--path') {
                $expect = true;
                continue;
            }

            if (str_starts_with($arg, '--path=')) {
                $val = trim((string) (explode('=', $arg, 2)[1] ?? ''));
                if ($val === '' || $val === '1') {
                    fwrite(STDERR, "Invalid value for --path\n");
                    return [$root, [], false];
                }
                $root = $val;
                continue;
            }

            if ($root === $default && !str_starts_with($arg, '--') && trim((string) $arg) !== '') {
                $root = (string) $arg;
                continue;
            }

            $filtered[] = $arg;
        }

        if ($expect) {
            fwrite(STDERR, "Missing value for --path\n");
            return [$root, [], false];
        }

        return [$root, $filtered, true];
    }

    private function loadBlackcatProfileConfigOrFail(?string $profilesPath): ?\BlackCat\Config\Config\ProfileConfig
    {
        $path = $profilesPath;
        if ($path === null) {
            $candidate = $this->config->workspaceRoot() . '/blackcat-config/config/profiles.php';
            if (is_file($candidate)) {
                $path = $candidate;
            }
        }

        if ($path === null) {
            fwrite(STDERR, "No profiles file provided. Use --profiles=... (or place it at blackcat-config/config/profiles.php).\n");
            return null;
        }

        try {
            return \BlackCat\Config\Config\ProfileConfig::fromFile($path);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return null;
        }
    }

    private function requireConfigProfile(\BlackCat\Config\Config\ProfileConfig $config, string $name): ?\BlackCat\Config\Profile\ConfigProfile
    {
        try {
            return $config->require($name);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return null;
        }
    }

    private function ensureBlackcatConfigAvailable(): bool
    {
        if (class_exists('\\BlackCat\\Config\\Runtime\\RuntimeConfigInstaller', false)) {
            return true;
        }

        $autoload = $this->config->workspaceRoot() . '/blackcat-config/src/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }

        if (class_exists('\\BlackCat\\Config\\Runtime\\RuntimeConfigInstaller')) {
            return true;
        }

        fwrite(STDERR, "blackcat-config is not available (install it or add it to the workspace).\n");
        return false;
    }

    /**
     * @param string[] $args
     * @return string[]
     */
    private static function stripRuntimeConfigArgs(array $args): array
    {
        $filtered = [];
        $skipNext = false;

        foreach ($args as $arg) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }

            if ($arg === '--config' || $arg === '--config-file') {
                $skipNext = true;
                continue;
            }

            if (str_starts_with($arg, '--config=') || str_starts_with($arg, '--config-file=')) {
                continue;
            }

            $filtered[] = $arg;
        }

        return $filtered;
    }

    /**
     * @param string[] $args
     * @return array{0:?string,1:string[]}
     */
    private static function consumeRuntimeConfigPath(array $args): array
    {
        $filtered = [];
        $path = null;
        $skipNext = false;

        foreach ($args as $arg) {
            if ($skipNext) {
                $skipNext = false;
                if ($path === null && $arg !== '') {
                    $path = $arg;
                }
                continue;
            }

            if ($arg === '--config' || $arg === '--config-file') {
                $skipNext = true;
                continue;
            }

            if (str_starts_with($arg, '--config=') || str_starts_with($arg, '--config-file=')) {
                $val = explode('=', $arg, 2)[1] ?? '';
                if ($path === null && $val !== '') {
                    $path = $val;
                }
                continue;
            }

            $filtered[] = $arg;
        }

        if ($path !== null) {
            $path = trim($path);
            if ($path === '' || $path === '1') {
                $path = null;
            }
        }

        return [$path, $filtered];
    }

    /**
     * @return array<int,array{name:string,status:string,message:string}>
     */
    private function runDoctorChecks(?string $runtimeConfigPath): array
    {
        $workspaceRoot = $this->config->workspaceRoot();
        $checks = [];

        foreach ($this->runtimeConfigDoctorChecks($workspaceRoot, $runtimeConfigPath) as $check) {
            $checks[] = $check;
        }
        foreach ($this->prometheusDoctorChecks($workspaceRoot) as $check) {
            $checks[] = $check;
        }
        foreach ($this->monitoringEndpointsDoctorChecks($workspaceRoot) as $check) {
            $checks[] = $check;
        }

        return $checks;
    }

    /**
     * @return array<int,array{name:string,status:string,message:string}>
     */
    private function runtimeConfigDoctorChecks(string $workspaceRoot, ?string $runtimeConfigPath): array
    {
        $needsCrypto = is_dir($workspaceRoot . '/blackcat-crypto') || is_dir($workspaceRoot . '/blackcat-database-crypto');
        $needsObservability = is_dir($workspaceRoot . '/blackcat-observability') || is_dir($workspaceRoot . '/blackcat-monitoring');

        if (!$needsCrypto && !$needsObservability && $runtimeConfigPath === null) {
            return [[
                'name' => 'runtime-config',
                'status' => 'skip',
                'message' => 'No runtime-config checks required for this workspace.',
            ]];
        }

        if (!class_exists('\\BlackCat\\Config\\Runtime\\Config')) {
            $autoload = $workspaceRoot . '/blackcat-config/src/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }

        if (!class_exists('\\BlackCat\\Config\\Runtime\\Config')) {
            return [[
                'name' => 'runtime-config',
                'status' => 'skip',
                'message' => 'blackcat-config is not available (skipping runtime config validation).',
            ]];
        }

        try {
            if (is_string($runtimeConfigPath) && $runtimeConfigPath !== '') {
                \BlackCat\Config\Runtime\Config::initFromJsonFileIfNeeded($runtimeConfigPath);
            } else {
                $scan = \BlackCat\Config\Runtime\ConfigBootstrap::scanFirstAvailableJsonFile();
                $repo = $scan['repo'] ?? null;
                if ($repo instanceof \BlackCat\Config\Runtime\ConfigRepository) {
                    \BlackCat\Config\Runtime\Config::initIfNeeded($repo);
                }
            }
        } catch (\Throwable $e) {
            return [[
                'name' => 'runtime-config.file',
                'status' => 'fail',
                'message' => $e->getMessage(),
            ]];
        }

        if (!\BlackCat\Config\Runtime\Config::isInitialized()) {
            $status = $needsCrypto ? 'fail' : 'skip';
            $msg = 'No usable runtime config file found.';

            try {
                $scan = \BlackCat\Config\Runtime\ConfigBootstrap::scanFirstAvailableJsonFile();
                $rej = $scan['rejected'];
                if ($rej !== []) {
                    $firstPath = (string) array_key_first($rej);
                    $firstReason = $rej[$firstPath] ?? null;
                    if (is_string($firstReason) && $firstReason !== '') {
                        $msg .= ' Rejected: ' . $firstPath . ' (' . $firstReason . ').';
                    }
                }
            } catch (\Throwable) {
            }

            if (class_exists('\\BlackCat\\Config\\Runtime\\RuntimeConfigInstaller')) {
                try {
                    $rec = \BlackCat\Config\Runtime\RuntimeConfigInstaller::recommendWritePath();
                    $recPath = $rec['path'] ?? null;
                    if (is_string($recPath) && $recPath !== '') {
                        $msg .= ' Recommended path: ' . $recPath . '.';
                        $msg .= ' Run: blackcat config runtime init';
                        $msg .= ' (or: blackcat config runtime init --path=' . $recPath . ')';
                    } else {
                        $msg .= ' Run: blackcat config runtime recommend --json for candidates.';
                    }
                } catch (\Throwable) {
                    $msg .= ' Run: blackcat config runtime init';
                }
            } else {
                $msg .= ' Run: blackcat config runtime init';
            }

            return [[
                'name' => 'runtime-config.file',
                'status' => $status,
                'message' => $msg,
            ]];
        }

        $checks = [];

        $repo = \BlackCat\Config\Runtime\Config::repo();

        if ($needsCrypto) {
            try {
                \BlackCat\Config\Runtime\RuntimeConfigValidator::assertCryptoConfig($repo);
                $checks[] = ['name' => 'runtime-config.crypto', 'status' => 'ok', 'message' => 'Crypto config is valid.'];
            } catch (\Throwable $e) {
                $checks[] = ['name' => 'runtime-config.crypto', 'status' => 'fail', 'message' => $e->getMessage()];
            }
        }

        if ($needsObservability) {
            try {
                \BlackCat\Config\Runtime\RuntimeConfigValidator::assertObservabilityConfig($repo);
                $checks[] = ['name' => 'runtime-config.observability', 'status' => 'ok', 'message' => 'Observability config is valid.'];
            } catch (\Throwable $e) {
                $checks[] = ['name' => 'runtime-config.observability', 'status' => 'fail', 'message' => $e->getMessage()];
            }
        }

        return $checks;
    }

    /**
     * @return array<int,array{name:string,status:string,message:string}>
     */
    private function prometheusDoctorChecks(string $workspaceRoot): array
    {
        if (!is_dir($workspaceRoot . '/blackcat-monitoring')) {
            return [[
                'name' => 'prometheus.targets',
                'status' => 'skip',
                'message' => 'blackcat-monitoring not present in this workspace.',
            ]];
        }

        $base = 'http://localhost:9090';

        try {
            $ready = $this->httpGet($base . '/-/ready', 1.5);
        } catch (\Throwable $e) {
            return [[
                'name' => 'prometheus.ready',
                'status' => 'skip',
                'message' => $e->getMessage() . ' (start: blackcat monitoring stack up)',
            ]];
        }

        if ($ready['status'] !== 200) {
            return [[
                'name' => 'prometheus.ready',
                'status' => 'fail',
                'message' => 'Prometheus not ready (HTTP ' . $ready['status'] . ').',
            ]];
        }

        try {
            $targets = $this->httpGetJson($base . '/api/v1/targets', 2.5);
        } catch (\Throwable $e) {
            return [[
                'name' => 'prometheus.targets',
                'status' => 'fail',
                'message' => $e->getMessage(),
            ]];
        }

        if (($targets['status'] ?? null) !== 'success') {
            return [[
                'name' => 'prometheus.targets',
                'status' => 'fail',
                'message' => 'Prometheus targets API returned non-success status.',
            ]];
        }

        $requiredJobs = ['blackcat-bench', 'blackcat-observability'];
        $activeTargets = $targets['data']['activeTargets'] ?? null;
        if (!is_array($activeTargets)) {
            return [[
                'name' => 'prometheus.targets',
                'status' => 'fail',
                'message' => 'Prometheus targets API response is missing activeTargets.',
            ]];
        }

        $jobHealth = [];
        foreach ($activeTargets as $t) {
            if (!is_array($t)) {
                continue;
            }
            $labels = $t['labels'] ?? null;
            if (!is_array($labels)) {
                continue;
            }
            $job = $labels['job'] ?? null;
            if (!is_string($job) || $job === '') {
                continue;
            }

            $health = $t['health'] ?? null;
            if (is_string($health) && $health !== '') {
                $jobHealth[$job] = $health;
            }
        }

        $missingOrDown = [];
        foreach ($requiredJobs as $job) {
            $health = $jobHealth[$job] ?? null;
            if ($health !== 'up') {
                $missingOrDown[] = $job . ':' . ($health ?? 'missing');
            }
        }

        if ($missingOrDown !== []) {
            return [[
                'name' => 'prometheus.targets',
                'status' => 'fail',
                'message' => 'Targets not UP: ' . implode(', ', $missingOrDown),
            ]];
        }

        return [[
            'name' => 'prometheus.targets',
            'status' => 'ok',
            'message' => 'All required targets are UP.',
        ]];
    }

    /**
     * @return array<int,array{name:string,status:string,message:string}>
     */
    private function monitoringEndpointsDoctorChecks(string $workspaceRoot): array
    {
        if (!is_dir($workspaceRoot . '/blackcat-monitoring')) {
            return [[
                'name' => 'monitoring.endpoints',
                'status' => 'skip',
                'message' => 'blackcat-monitoring not present in this workspace.',
            ]];
        }

        $checks = [];

        // Grafana
        try {
            $health = $this->httpGetJson('http://localhost:3000/api/health', 2.0);
            $db = $health['database'] ?? null;
            if ($db === 'ok') {
                $checks[] = ['name' => 'grafana.health', 'status' => 'ok', 'message' => 'Grafana is healthy.'];
            } else {
                $checks[] = ['name' => 'grafana.health', 'status' => 'fail', 'message' => 'Grafana health check failed (database != ok).'];
            }
        } catch (\Throwable $e) {
            $checks[] = ['name' => 'grafana.health', 'status' => 'skip', 'message' => $e->getMessage()];
        }

        // Loki
        try {
            $res = $this->httpGet('http://localhost:3100/ready', 2.0);
            if ($res['status'] === 200 && str_contains($res['body'], 'ready')) {
                $checks[] = ['name' => 'loki.ready', 'status' => 'ok', 'message' => 'Loki is ready.'];
            } else {
                $checks[] = ['name' => 'loki.ready', 'status' => 'fail', 'message' => 'Loki not ready (HTTP ' . $res['status'] . ').'];
            }
        } catch (\Throwable $e) {
            $checks[] = ['name' => 'loki.ready', 'status' => 'skip', 'message' => $e->getMessage()];
        }

        // Promtail (metrics endpoint; in the dev stack it is exposed on :9080).
        try {
            $res = $this->httpGet('http://localhost:9080/metrics', 2.0);
            if ($res['status'] !== 200) {
                $checks[] = ['name' => 'promtail.metrics', 'status' => 'fail', 'message' => 'Promtail metrics not reachable (HTTP ' . $res['status'] . ').'];
            } elseif (!str_contains($res['body'], 'promtail_build_info')) {
                $checks[] = ['name' => 'promtail.metrics', 'status' => 'fail', 'message' => 'Promtail metrics response missing promtail_build_info.'];
            } else {
                $checks[] = ['name' => 'promtail.metrics', 'status' => 'ok', 'message' => 'Promtail metrics OK.'];
            }
        } catch (\Throwable $e) {
            $checks[] = ['name' => 'promtail.metrics', 'status' => 'skip', 'message' => $e->getMessage()];
        }

        // Bench exporter sanity (/metrics should always work, even with 0 CSVs).
        try {
            $res = $this->httpGet('http://localhost:9464/metrics', 2.0);
            if ($res['status'] !== 200) {
                $checks[] = ['name' => 'bench-exporter.metrics', 'status' => 'fail', 'message' => 'Bench exporter not reachable (HTTP ' . $res['status'] . ').'];
            } elseif (!str_contains($res['body'], 'bench_ops_total')) {
                $checks[] = ['name' => 'bench-exporter.metrics', 'status' => 'fail', 'message' => 'Bench exporter response missing bench_ops_total.'];
            } else {
                $checks[] = ['name' => 'bench-exporter.metrics', 'status' => 'ok', 'message' => 'Bench exporter /metrics OK.'];
            }
        } catch (\Throwable $e) {
            $checks[] = ['name' => 'bench-exporter.metrics', 'status' => 'skip', 'message' => $e->getMessage()];
        }

        // Observability exporter sanity (may be empty when no metrics were produced yet).
        try {
            $res = $this->httpGet('http://localhost:9465/metrics', 2.0);
            if ($res['status'] !== 200) {
                $checks[] = ['name' => 'observability-exporter.metrics', 'status' => 'fail', 'message' => 'Observability exporter not reachable (HTTP ' . $res['status'] . ').'];
            } else {
                $bytes = strlen($res['body']);
                $checks[] = [
                    'name' => 'observability-exporter.metrics',
                    'status' => 'ok',
                    'message' => $bytes > 0 ? ('Observability exporter /metrics OK (' . $bytes . ' bytes).') : 'Observability exporter /metrics OK (no metrics yet).',
                ];
            }
        } catch (\Throwable $e) {
            $checks[] = ['name' => 'observability-exporter.metrics', 'status' => 'skip', 'message' => $e->getMessage()];
        }

        return $checks;
    }

    /**
     * @return array{status:int,body:string}
     */
    private function httpGet(string $url, float $timeoutSeconds): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'header' => "User-Agent: blackcat-cli\r\n",
            ],
        ]);

        /** @var list<string> $http_response_header */
        $http_response_header = [];
        $body = @file_get_contents($url, false, $ctx);
        $headers = $http_response_header;
        $status = 0;
        if ($headers !== []) {
            $first = (string) ($headers[0] ?? '');
            if (preg_match('/\\s(\\d{3})\\s/', $first, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($body === false) {
            throw new \RuntimeException('HTTP request failed: ' . $url);
        }

        return [
            'status' => $status,
            'body' => $body,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function httpGetJson(string $url, float $timeoutSeconds): array
    {
        $res = $this->httpGet($url, $timeoutSeconds);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($res['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid JSON from: ' . $url, 0, $e);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Expected JSON object from: ' . $url);
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }
}
