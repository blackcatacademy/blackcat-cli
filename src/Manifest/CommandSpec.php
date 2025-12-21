<?php

declare(strict_types=1);

namespace BlackCat\Cli\Manifest;

final class CommandSpec
{
    /**
     * @param list<string> $args
     */
    public function __construct(
        private readonly string $componentId,
        private readonly string $componentRoot,
        private readonly string $command,
        private readonly string $type,
        private readonly string $summary,
        private readonly ?string $runner,
        private readonly ?string $script,
        private readonly array $args,
        private readonly string $manifestPath
    ) {
    }

    public function componentId(): string
    {
        return $this->componentId;
    }

    public function componentRoot(): string
    {
        return $this->componentRoot;
    }

    public function command(): string
    {
        return $this->command;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function runner(): ?string
    {
        return $this->runner;
    }

    public function script(): ?string
    {
        return $this->script;
    }

    /**
     * @return list<string>
     */
    public function args(): array
    {
        return $this->args;
    }

    public function manifestPath(): string
    {
        return $this->manifestPath;
    }

    public function isProxy(): bool
    {
        return $this->type === 'proxy';
    }

    public function isBuiltin(): bool
    {
        return $this->type === 'builtin';
    }
}

