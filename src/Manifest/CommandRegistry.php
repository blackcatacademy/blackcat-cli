<?php

declare(strict_types=1);

namespace BlackCat\Cli\Manifest;

use BlackCat\CliSpec\Manifest\ManifestValidator;
use JsonException;

final class CommandRegistry
{
    /** @var array<string,CommandSpec> */
    private array $commands;

    /** @var ManifestError[] */
    private array $errors;

    /**
     * @param array<string,CommandSpec> $commands
     * @param ManifestError[] $errors
     */
    private function __construct(array $commands, array $errors)
    {
        $this->commands = $commands;
        $this->errors = $errors;
    }

    public static function fromWorkspaceRoot(string $workspaceRoot): self
    {
        $commands = [];
        $errors = [];

        if (!class_exists(ManifestValidator::class)) {
            return new self([], [
                new ManifestError('', 'Missing dependency: blackcat-cli-spec (ManifestValidator not found).'),
            ]);
        }

        $glob = rtrim($workspaceRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'blackcat-*' . DIRECTORY_SEPARATOR . 'blackcat-cli.json';
        $paths = glob($glob) ?: [];
        sort($paths);

        foreach ($paths as $manifestPath) {
            if (!is_string($manifestPath) || $manifestPath === '' || !is_file($manifestPath)) {
                continue;
            }

            [$loaded, $loadErrors] = self::loadManifestFile($manifestPath);
            foreach ($loadErrors as $error) {
                $errors[] = $error;
            }

            foreach ($loaded as $cmd => $spec) {
                if (isset($commands[$cmd])) {
                    $errors[] = new ManifestError(
                        $manifestPath,
                        "Duplicate command '{$cmd}' (already provided by {$commands[$cmd]->manifestPath()})."
                    );
                    continue;
                }
                $commands[$cmd] = $spec;
            }
        }

        return new self($commands, $errors);
    }

    /**
     * @return array<string,CommandSpec>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    public function find(string $command): ?CommandSpec
    {
        return $this->commands[$command] ?? null;
    }

    /**
     * @return ManifestError[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array{0:array<string,CommandSpec>,1:list<ManifestError>}
     */
    private static function loadManifestFile(string $path): array
    {
        $errors = [];

        $result = ManifestValidator::validateFile($path);
        if (!$result->isValid()) {
            foreach ($result->errors() as $error) {
                $where = $error->path() === '' ? '(root)' : $error->path();
                $errors[] = new ManifestError($path, $where . ': ' . $error->message());
            }
            return [[], $errors];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return [[], [new ManifestError($path, 'Unable to read manifest file.')]];
        }

        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [[], [new ManifestError($path, 'Invalid JSON: ' . $e->getMessage())]];
        }

        if (!is_array($data)) {
            return [[], [new ManifestError($path, 'Manifest root must be an object.')]];
        }

        /** @var array<string,mixed> $data */
        $component = $data['component'] ?? null;
        if (!is_array($component) || !is_string($component['id'] ?? null)) {
            return [[], [new ManifestError($path, 'Missing component.id.')]];
        }

        $componentId = (string) $component['id'];
        $componentRoot = dirname($path);

        $cli = $data['cli'] ?? null;
        if (!is_array($cli) || !is_array($cli['entrypoints'] ?? null)) {
            return [[], [new ManifestError($path, 'Missing cli.entrypoints.')]];
        }

        /** @var list<mixed> $entrypoints */
        $entrypoints = $cli['entrypoints'];

        $loaded = [];
        foreach ($entrypoints as $entrypoint) {
            if (!is_array($entrypoint)) {
                continue;
            }

            $cmd = $entrypoint['command'] ?? null;
            $type = $entrypoint['type'] ?? null;
            $summary = $entrypoint['summary'] ?? null;
            if (!is_string($cmd) || $cmd === '' || !is_string($type) || $type === '' || !is_string($summary) || $summary === '') {
                continue;
            }

            if ($type === 'proxy') {
                $proxy = $entrypoint['proxy'] ?? null;
                if (!is_array($proxy)) {
                    $errors[] = new ManifestError($path, "Entry point '{$cmd}' is type=proxy but proxy section is missing.");
                    continue;
                }

                $runner = $proxy['runner'] ?? null;
                $script = $proxy['script'] ?? null;
                if (!is_string($runner) || $runner === '' || !is_string($script) || $script === '') {
                    $errors[] = new ManifestError($path, "Entry point '{$cmd}' proxy.runner/proxy.script must be non-empty strings.");
                    continue;
                }

                $resolvedScript = self::resolveScriptPath($componentRoot, $script);
                $args = $proxy['args'] ?? [];
                if (!is_array($args)) {
                    $args = [];
                }

                /** @var list<string> $args */
                $args = array_map(static fn ($v): string => (string) $v, array_values($args));

                $loaded[$cmd] = new CommandSpec(
                    $componentId,
                    $componentRoot,
                    $cmd,
                    $type,
                    $summary,
                    $runner,
                    $resolvedScript,
                    $args,
                    $path
                );
                continue;
            }

            if ($type === 'builtin') {
                $loaded[$cmd] = new CommandSpec(
                    $componentId,
                    $componentRoot,
                    $cmd,
                    $type,
                    $summary,
                    null,
                    null,
                    [],
                    $path
                );
                continue;
            }

            $errors[] = new ManifestError($path, "Entry point '{$cmd}' has unsupported type '{$type}'.");
        }

        return [$loaded, $errors];
    }

    private static function resolveScriptPath(string $componentRoot, string $script): string
    {
        $script = trim($script);
        if ($script === '') {
            return $script;
        }

        if (str_starts_with($script, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $script) === 1) {
            return $script;
        }

        return rtrim($componentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $script);
    }
}

