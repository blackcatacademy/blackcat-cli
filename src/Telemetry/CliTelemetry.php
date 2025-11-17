<?php

declare(strict_types=1);

namespace BlackCat\Cli\Telemetry;

final class CliTelemetry
{
    /** @var array<string,int> */
    private array $counters = [];

    public function __construct(
        private readonly string $eventsFile,
        private readonly string $metricsFile
    ) {
        $this->ensureDirectory($eventsFile);
        $this->ensureDirectory($metricsFile);
    }

    public function record(string $command, int $exitCode, float $durationSeconds): void
    {
        $event = [
            'timestamp' => date('c'),
            'command' => $command,
            'exit_code' => $exitCode,
            'duration_ms' => (int) round($durationSeconds * 1000),
            'host' => gethostname() ?: 'localhost',
            'pid' => getmypid(),
        ];

        file_put_contents(
            $this->eventsFile,
            json_encode($event, JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND
        );

        $this->counters['_total'] = ($this->counters['_total'] ?? 0) + 1;
        $this->counters[$command] = ($this->counters[$command] ?? 0) + 1;
    }

    public function flush(): void
    {
        if ($this->counters === []) {
            return;
        }

        $lines = [
            '# HELP blackcat_cli_command_total Number of commands recorded by blackcat-cli',
            '# TYPE blackcat_cli_command_total counter',
        ];

        foreach ($this->counters as $command => $count) {
            $lines[] = sprintf(
                'blackcat_cli_command_total{command="%s"} %d',
                $command,
                $count
            );
        }

        file_put_contents($this->metricsFile, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private function ensureDirectory(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}
