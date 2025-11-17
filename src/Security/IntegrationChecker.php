<?php

declare(strict_types=1);

namespace BlackCat\Cli\Security;

use BlackCat\Cli\Config\CliConfig;

final class IntegrationChecker
{
    public function __construct(private readonly CliConfig $config)
    {
    }

    /**
     * @return array<int,array{command:string,runner:string,script:string,resolved:?string,exists:bool,allowed:bool}>
     */
    public function inspect(): array
    {
        $results = [];

        foreach ($this->config->commands() as $name => $definition) {
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

        return $results;
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
