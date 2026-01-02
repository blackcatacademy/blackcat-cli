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

        $this->counters['_total'] = ($this->counters['_total'] ?? 0) + 1;
        $this->counters[$command] = ($this->counters[$command] ?? 0) + 1;

        try {
            $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                return;
            }

            @file_put_contents(
                $this->eventsFile,
                $json . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );

            if (DIRECTORY_SEPARATOR !== '\\') {
                @chmod($this->eventsFile, 0600);
            }
        } catch (\Throwable) {
            // Telemetry must be best-effort; never crash CLI execution.
        }
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
            $label = self::escapePrometheusLabelValue((string) $command);
            $lines[] = sprintf(
                'blackcat_cli_command_total{command="%s"} %d',
                $label,
                $count
            );
        }

        try {
            @file_put_contents($this->metricsFile, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
            if (DIRECTORY_SEPARATOR !== '\\') {
                @chmod($this->metricsFile, 0600);
            }
        } catch (\Throwable) {
            // Telemetry must be best-effort; never crash CLI execution.
        }
    }

    private function ensureDirectory(string $file): void
    {
        $dir = dirname($file);
        if ($dir === '' || $dir === '.' || $dir === DIRECTORY_SEPARATOR) {
            return;
        }
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                return;
            }

            if (DIRECTORY_SEPARATOR !== '\\') {
                @chmod($dir, 0750);
            }
        }
    }

    private static function escapePrometheusLabelValue(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace("\n", '\\n', $value);
        $value = str_replace("\r", '\\r', $value);
        $value = str_replace("\t", '\\t', $value);
        return str_replace('"', '\\"', $value);
    }
}
