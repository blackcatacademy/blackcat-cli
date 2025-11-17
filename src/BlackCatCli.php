<?php

declare(strict_types=1);

namespace BlackCat\Cli;

use BlackCat\Cli\Config\CliConfig;
use BlackCat\Cli\Security\IntegrationChecker;
use BlackCat\Cli\Telemetry\CliTelemetry;
use InvalidArgumentException;

final class BlackCatCli
{
    private function __construct(
        private readonly CliConfig $config,
        private readonly CliTelemetry $telemetry
    ) {
    }

    public static function run(array $argv): int
    {
        [$options, $args] = self::parseArguments($argv);
        $command = $args[0] ?? 'help';
        $commandArgs = array_slice($args, 1);

        try {
            $config = CliConfig::fromFile($options['config'] ?? null);
        } catch (InvalidArgumentException $e) {
            fwrite(STDERR, '[blackcat-cli] ' . $e->getMessage() . PHP_EOL);
            return 1;
        }

        $telemetry = new CliTelemetry($config->telemetryEventsFile(), $config->telemetryMetricsFile());
        $app = new self($config, $telemetry);

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

            if ($arg === '--config') {
                $options['config'] = array_shift($args) ?: null;
                continue;
            }

            if (str_starts_with((string) $arg, '--config=')) {
                $options['config'] = substr((string) $arg, 9) ?: null;
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
            default => $this->runConfiguredCommand($command, $args),
        };
    }

    private function printHelp(): int
    {
        echo "BlackCat CLI\n";
        echo "Usage: blackcat [--config=path] <command> [args...]\n\n";
        echo "Commands:\n";
        echo "  configure <shopping-list.json>    Configure shopping list via installer\n";
        echo "  install <shopping-list.json>      Run blackcat-install pipeline\n";
        echo "  status [--json]                   List configured proxies + status\n";
        echo "  verify [--json]                   Security + integration checks\n";
        echo "  crypto|observability|agent|auth|db|governance|security ... (proxied)\n";
        echo "\nSet BLACKCAT_CLI_CONFIG or pass --config to point at another config file.\n";
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
    private function runConfiguredCommand(string $command, array $args): int
    {
        try {
            $spec = $this->config->command($command);
        } catch (InvalidArgumentException $e) {
            return $this->unknown($command);
        }

        $cmd = array_merge([$spec['runner'], $spec['script']], $spec['args'], $args);
        return $this->runProcess($cmd);
    }

    /**
     * @param string[] $args
     */
    private function runStatus(array $args): int
    {
        [$json, $remaining] = $this->consumeFlag($args, '--json');
        if ($remaining !== []) {
            fwrite(STDERR, 'status does not accept additional arguments' . PHP_EOL);
            return 1;
        }

        $checker = new IntegrationChecker($this->config);
        $results = $checker->inspect();

        if ($json) {
            echo json_encode([
                'workspace' => $this->config->workspaceRoot(),
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
        if ($remaining !== []) {
            fwrite(STDERR, 'verify does not accept additional arguments' . PHP_EOL);
            return 1;
        }

        $checker = new IntegrationChecker($this->config);
        $results = $checker->inspect();
        $violations = array_values(array_filter(
            $results,
            static fn (array $row): bool => !$row['exists'] || !$row['allowed']
        ));

        if ($json) {
            echo json_encode([
                'workspace' => $this->config->workspaceRoot(),
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

        if ($violations !== []) {
            fwrite(STDERR, 'Integration check failed for ' . count($violations) . ' command(s).' . PHP_EOL);
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

        return proc_close($process) ?? 0;
    }
}
