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
        echo "  verify [--json]                   Security + integration checks\n";
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
        $remaining = self::stripRuntimeConfigArgs($remaining);
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

        if ($json) {
            echo json_encode([
                'workspace' => $this->config->workspaceRoot(),
                'manifest_errors' => array_map(
                    static fn ($e): array => ['manifest' => $e->manifestPath(), 'message' => $e->message()],
                    $manifestErrors
                ),
                'violations' => $violations,
                'commands' => $results,
            ], JSON_PRETTY_PRINT) . PHP_EOL;
        } else {
            foreach ($results as $row) {
                $path = $row['resolved'] ?? $row['script'];
                $status = ($row['exists'] && $row['allowed']) ? 'OK' : 'FAIL';
                echo sprintf('[%s] %-12s %s' . PHP_EOL, $status, $row['command'], $path);
            }
        }

        if ($manifestErrors !== []) {
            fwrite(STDERR, 'Manifest validation failed for ' . count($manifestErrors) . ' file(s).' . PHP_EOL);
        }

        if ($violations !== [] || $manifestErrors !== []) {
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
}
