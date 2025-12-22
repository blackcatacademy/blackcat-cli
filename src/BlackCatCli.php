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
            'monitoring' => $this->runMonitoring($args),
            'observability' => $this->runObservability($args),
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
            echo "  runtime recommend             Recommend best write location\n";
            echo "  runtime init [--force]        Create runtime config at best location\n";
            echo "         [--path=FILE]          Force specific path\n";
            echo "         [--json]               JSON output\n";
            echo "\nExamples:\n";
            echo "  blackcat config runtime recommend\n";
            echo "  blackcat config runtime init\n";
            echo "  blackcat config runtime init --path=/etc/blackcat/config.runtime.json --force\n";
            return 0;
        }

        $cmd = $sub;
        if ($cmd === 'runtime') {
            $action = (string) ($rest[0] ?? 'help');
            if ($action === '' || $action === 'help' || $action === '--help' || $action === '-h') {
                echo "config runtime\n";
                echo "Usage: blackcat config runtime <paths|recommend|init> [options]\n\n";
                echo "Subcommands:\n";
                echo "  paths                 Print read/write candidate paths\n";
                echo "  recommend             Recommend best write location\n";
                echo "  init [--force]        Create runtime config at best location\n";
                echo "       [--path=FILE]    Force specific path\n";
                echo "       [--json]         JSON output\n";
                return 0;
            }
            $rest = array_slice($rest, 1);
            $cmd = 'runtime ' . $action;
        }

        return match ($cmd) {
            'runtime paths' => $this->runConfigRuntimePaths($rest),
            'runtime recommend' => $this->runConfigRuntimeRecommend($rest),
            'runtime init' => $this->runConfigRuntimeInit($rest),
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
    private function runConfigRuntimeInit(array $args): int
    {
        [$json, $args] = $this->consumeFlag($args, '--json');
        [$force, $args] = $this->consumeFlag($args, '--force');

        $path = null;
        $filtered = [];
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

            $filtered[] = $arg;
        }

        if ($expectPath) {
            fwrite(STDERR, "Missing value for --path\n");
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
            $res = \BlackCat\Config\Runtime\RuntimeConfigInstaller::init([], $path, $force);
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

        $checks = [];
        $checks[] = [
            'name' => 'runtime-config.file',
            'status' => 'ok',
            'message' => 'Runtime config initialized.',
        ];

        try {
            if (is_string($runtimeConfigPath) && $runtimeConfigPath !== '') {
                \BlackCat\Config\Runtime\Config::initFromJsonFileIfNeeded($runtimeConfigPath);
            } else {
                \BlackCat\Config\Runtime\Config::tryInitFromFirstAvailableJsonFile();
            }
        } catch (\Throwable $e) {
            return [[
                'name' => 'runtime-config.file',
                'status' => 'fail',
                'message' => $e->getMessage(),
            ]];
        }

        if (!\BlackCat\Config\Runtime\Config::isInitialized()) {
            $status = $runtimeConfigPath !== null ? 'fail' : 'skip';
            $checks[0] = [
                'name' => 'runtime-config.file',
                'status' => $status,
                'message' => 'No runtime config file found (use --config=FILE or install to /etc/blackcat/config.runtime.json).',
            ];
            return $checks;
        }

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
