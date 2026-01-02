<?php

declare(strict_types=1);

namespace BlackCat\Cli\Tests;

use PHPUnit\Framework\TestCase;

final class LibexecDbOptionalConfigTest extends TestCase
{
    public function testLibexecDbHelpWorksWithoutBlackcatConfig(): void
    {
        $workspace = sys_get_temp_dir() . '/blackcat-cli-libexec-db-' . bin2hex(random_bytes(4));
        mkdir($workspace, 0777, true);

        mkdir($workspace . '/vendor', 0777, true);
        file_put_contents($workspace . '/vendor/autoload.php', "<?php\n");

        mkdir($workspace . '/blackcat-cli/libexec', 0777, true);
        copy(dirname(__DIR__) . '/libexec/bootstrap.php', $workspace . '/blackcat-cli/libexec/bootstrap.php');
        copy(dirname(__DIR__) . '/libexec/db', $workspace . '/blackcat-cli/libexec/db');

        mkdir($workspace . '/blackcat-core/src/Database', 0777, true);

        file_put_contents(
            $workspace . '/blackcat-core/src/Database.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace BlackCat\Core;

final class Database
{
    private static bool $initialized = false;

    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function init(array $config): void
    {
        self::$initialized = true;
    }

    public static function getInstance(): object
    {
        throw new \RuntimeException('Not implemented in test stub.');
    }
}
PHP
        );

        file_put_contents(
            $workspace . '/blackcat-core/src/Database/DbBootstrap.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace BlackCat\Core\Database;

final class DbBootstrap
{
    public static function initFromSecretsAgentIfNeeded(string $appName): void
    {
    }
}
PHP
        );

        $cmd = [
            PHP_BINARY,
            $workspace . '/blackcat-cli/libexec/db',
            'help',
        ];

        $res = self::runProcess($cmd, $workspace);
        self::assertSame(0, $res['exit_code'], $res['stderr']);
        self::assertStringContainsString('db usage:', $res['stdout']);
    }

    /**
     * @param list<string> $cmd
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private static function runProcess(array $cmd, string $cwd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Unable to start process.');
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}

