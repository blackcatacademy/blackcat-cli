<?php

declare(strict_types=1);

namespace BlackCat\Cli\Config;

use InvalidArgumentException;

final class CliConfig
{
    /** @var array<string,mixed> */
    private array $payload;

    private function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public static function fromFile(?string $path = null): self
    {
        $defaultPath = dirname(__DIR__, 2) . '/config/example.cli.php';
        $path = $path ?? getenv('BLACKCAT_CLI_CONFIG') ?: $defaultPath;

        if (!is_file($path)) {
            throw new InvalidArgumentException("CLI config missing: {$path}");
        }

        $payload = require $path;
        if (!is_array($payload)) {
            throw new InvalidArgumentException('CLI config must return array payload.');
        }

        $profileEnv = self::loadProfileEnv($payload);
        $payload = self::resolve($payload, $profileEnv);

        return new self($payload);
    }

    /**
     * @return array<string,array{runner:string,script:string,args:array<int,string>}>
     */
    public function commands(): array
    {
        $commands = $this->payload['commands'] ?? [];
        if (!is_array($commands)) {
            return [];
        }

        $normalized = [];
        foreach ($commands as $name => $definition) {
            if (!is_array($definition) || !isset($definition['script'])) {
                continue;
            }

            $runner = (string) ($definition['runner'] ?? 'php');
            $script = (string) $definition['script'];
            $args = $definition['args'] ?? [];
            if (!is_array($args)) {
                $args = [];
            }

            $normalized[(string) $name] = [
                'runner' => $runner,
                'script' => $script,
                'args' => array_map(static fn ($value): string => (string) $value, array_values($args)),
            ];
        }

        return $normalized;
    }

    /**
     * @return array{runner:string,script:string,args:array<int,string>}
     */
    public function command(string $name): array
    {
        $commands = $this->commands();
        if (!isset($commands[$name])) {
            throw new InvalidArgumentException("Command config missing: {$name}");
        }

        return $commands[$name];
    }

    public function telemetryEventsFile(): string
    {
        $telemetry = $this->payload['telemetry']['events_file'] ?? (dirname(__DIR__, 2) . '/var/cli-events.ndjson');
        return (string) $telemetry;
    }

    public function telemetryMetricsFile(): string
    {
        $telemetry = $this->payload['telemetry']['metrics_file'] ?? (dirname(__DIR__, 2) . '/var/cli-metrics.prom');
        return (string) $telemetry;
    }

    public function defaultShoppingList(): ?string
    {
        $shopping = $this->payload['defaults']['shopping_list'] ?? null;
        return $shopping ? (string) $shopping : null;
    }

    /**
     * @return string[]
     */
    public function allowedRoots(): array
    {
        $roots = $this->payload['security']['allowed_roots'] ?? [];
        if (!is_array($roots) || $roots === []) {
            $roots = [$this->workspaceRoot()];
        }

        $normalized = [];
        foreach ($roots as $root) {
            $candidate = (string) $root;
            if ($candidate === '') {
                continue;
            }
            $resolved = realpath($candidate) ?: $candidate;
            $normalized[] = rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        return $normalized;
    }

    public function workspaceRoot(): string
    {
        $root = $this->payload['workspace_root'] ?? null;
        if (is_string($root) && $root !== '') {
            return rtrim($root, DIRECTORY_SEPARATOR);
        }

        return rtrim((string) dirname(__DIR__, 3), DIRECTORY_SEPARATOR);
    }

    /**
     * @param mixed $value
     * @param array<string,string> $profileEnv
     * @return mixed
     */
    private static function resolve(mixed $value, array $profileEnv): mixed
    {
        if (is_string($value)) {
            if (preg_match('/^\$\{env:([^}]+)}/', $value, $m)) {
                $env = $profileEnv[$m[1]] ?? getenv($m[1]) ?: '';
                return (string) $env;
            }

            if (preg_match('/^\$\{file:([^}]+)}/', $value, $m)) {
                return is_file($m[1]) ? trim((string) file_get_contents($m[1])) : '';
            }

            return $value;
        }

        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $key => $inner) {
                $resolved[$key] = self::resolve($inner, $profileEnv);
            }
            return $resolved;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    private static function loadProfileEnv(array $payload): array
    {
        $profile = $payload['config_profile'] ?? null;
        if (!is_array($profile)) {
            return [];
        }

        $file = $profile['file'] ?? null;
        if (!is_string($file) || !is_file($file)) {
            return [];
        }

        $targetEnv = $profile['environment'] ?? null;
        $targetName = $profile['name'] ?? null;

        $autoload = dirname(__DIR__, 3) . '/blackcat-config/src/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }

        if (class_exists('\\BlackCat\\Config\\Config\\ProfileConfig')) {
            $profiles = \BlackCat\Config\Config\ProfileConfig::fromFile($file)->profiles();
            foreach ($profiles as $configProfile) {
                $match = ($targetName && $configProfile->name() === $targetName)
                    || ($targetEnv && $configProfile->environment() === $targetEnv);
                if ($match) {
                    return $configProfile->env();
                }
            }
        }

        $raw = require $file;
        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as $candidate) {
            $match = ($targetName && ($candidate['name'] ?? null) === $targetName)
                || ($targetEnv && ($candidate['environment'] ?? null) === $targetEnv);
            if ($match) {
                $env = $candidate['env'] ?? [];
                if (is_array($env)) {
                    return array_map(static fn ($value): string => (string) $value, $env);
                }
            }
        }

        return [];
    }
}
