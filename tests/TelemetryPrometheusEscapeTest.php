<?php

declare(strict_types=1);

namespace BlackCat\Cli\Tests;

use BlackCat\Cli\Telemetry\CliTelemetry;
use PHPUnit\Framework\TestCase;

final class TelemetryPrometheusEscapeTest extends TestCase
{
    public function testPrometheusLabelValueIsEscaped(): void
    {
        $dir = sys_get_temp_dir() . '/blackcat-cli-telemetry-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);

        $events = $dir . '/events.ndjson';
        $metrics = $dir . '/metrics.prom';

        $telemetry = new CliTelemetry($events, $metrics);
        $telemetry->record("evil\"cmd\nx\\y", 0, 0.01);
        $telemetry->flush();

        $raw = file_get_contents($metrics);
        self::assertIsString($raw);

        $expected = 'blackcat_cli_command_total{command="evil' . '\\"' . 'cmd\\nx\\\\y"} 1';
        self::assertStringContainsString($expected, $raw);
    }
}
