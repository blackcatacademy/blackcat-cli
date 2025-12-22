# BlackCat CLI

`blackcat-cli` is an **optional** CLI frontend for the BlackCat ecosystem.

Goals:
- a single, consistent CLI UX (`blackcat` / `bc`)
- security-oriented integration checks (`blackcat verify`)
- no CLI logic scattered across core libraries (CLI stays here; libraries stay pure)
- manifest-driven discovery (future-proof docs/help generation)

## Usage

```bash
# configure a shopping list (installer integration)
php bin/blackcat configure shopping-list.json

# run installation pipeline
php bin/blackcat install shopping-list.json

# proxies to installed component CLIs (when configured)
php bin/blackcat crypto metrics:export prom
php bin/blackcat observability events:tail
php bin/blackcat observability config:print
php bin/blackcat agent template shopping-list
php bin/blackcat auth help
php bin/blackcat db --help
php bin/blackcat governance policy:list
php bin/blackcat security checklist stride

# built-in (manifest-discovered) db-crypto tooling
php bin/blackcat db-crypto plan --schema-source=packages
php bin/blackcat db-crypto telemetry --out=telemetry/db-crypto-metrics.json
```

## Manifest discovery (Stage 2)

Component repositories can expose a `blackcat-cli.json` manifest at repo root.
`blackcat-cli` scans the workspace (`blackcat-*/blackcat-cli.json`) and registers commands.

Manifests are validated by `blackcat-cli-spec`.

## Configuration
The legacy Stage 1 config loader is still available (`config/example.cli.php`) to keep proxy commands stable.
Override via `BLACKCAT_CLI_CONFIG` or `--cli-config=/path/to/cli.php`.

```php
$config = BlackCat\Cli\Config\CliConfig::fromFile();
$install = $config->command('install');
```

## Telemetry & security
- Each run appends an event to `var/cli-events.ndjson` and Prometheus metrics to `var/cli-metrics.prom`.
- `blackcat status [--json]` lists configured/discovered commands and their resolved targets.
- `blackcat verify [--json] [--config=FILE]` performs security/integration checks (exit 2 on failure) and, when available, runs doctor-style checks:
  - validates runtime config via `blackcat-config` (when present)
  - checks Prometheus targets (when `blackcat-monitoring` is present and Prometheus is reachable)

## Tests

```bash
bash tests/test.cli
php vendor/bin/phpunit
php vendor/bin/phpstan analyse --configuration=phpstan.neon
```
