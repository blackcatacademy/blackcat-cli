<?php

declare(strict_types=1);

namespace BlackCat\Cli\Security;

use BlackCat\Cli\Config\CliConfig;
use BlackCat\Cli\Manifest\CommandRegistry;
use BlackCat\Cli\Manifest\ManifestError;

final class IntegrationChecker
{
    public function __construct(
        private readonly CliConfig $config,
        private readonly CommandRegistry $registry
    )
    {
    }

    /**
     * @return array<int,array{command:string,runner:string,script:string,resolved:?string,exists:bool,allowed:bool}>
     */
    public function inspect(): array
    {
        $results = [];

        $configCommands = $this->config->commands();

        foreach ($configCommands as $name => $definition) {
            $script = $definition['script'];
            $resolved = realpath($script) ?: null;
            $exists = $resolved !== null && is_file($resolved);
            $allowed = $exists ? $this->isAllowed($resolved) : false;

            $results[] = [
                'command' => $name,
                'runner' => $definition['runner'],
                'script' => $script,
                'resolved' => $resolved,
                'exists' => $exists,
                'allowed' => $allowed,
            ];
        }

        foreach ($this->registry->commands() as $name => $spec) {
            if (isset($configCommands[$name])) {
                continue;
            }

            if ($spec->isBuiltin()) {
                $results[] = [
                    'command' => $name,
                    'runner' => 'builtin',
                    'script' => '(builtin)',
                    'resolved' => null,
                    'exists' => true,
                    'allowed' => true,
                ];
                continue;
            }

            $script = (string) ($spec->script() ?? '');
            $resolved = realpath($script) ?: null;
            $exists = $resolved !== null && is_file($resolved);
            $allowed = $exists ? $this->isAllowed($resolved) : false;

            $results[] = [
                'command' => $name,
                'runner' => (string) ($spec->runner() ?? 'php'),
                'script' => $script,
                'resolved' => $resolved,
                'exists' => $exists,
                'allowed' => $allowed,
            ];
        }

        return $results;
    }

    /**
     * @return ManifestError[]
     */
    public function manifestErrors(): array
    {
        return $this->registry->errors();
    }

    /**
     * @param array<int,array{command:string,runner:string,script:string,resolved:?string,exists:bool,allowed:bool}> $results
     */
    public function hasViolations(array $results): bool
    {
        foreach ($results as $row) {
            if (!$row['exists'] || !$row['allowed']) {
                return true;
            }
        }

        if ($this->registry->errors() !== []) {
            return true;
        }

        return false;
    }

    private function isAllowed(?string $path): bool
    {
        if ($path === null) {
            return false;
        }

        foreach ($this->config->allowedRoots() as $root) {
            if ($root === '') {
                continue;
            }

            if (str_starts_with($path, $root)) {
                return true;
            }
        }

        return false;
    }
}
