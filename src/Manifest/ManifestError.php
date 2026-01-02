<?php

declare(strict_types=1);

namespace BlackCat\Cli\Manifest;

final class ManifestError
{
    public function __construct(
        private readonly string $manifestPath,
        private readonly string $message
    ) {
    }

    public function manifestPath(): string
    {
        return $this->manifestPath;
    }

    public function message(): string
    {
        return $this->message;
    }
}

