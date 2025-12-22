# BlackCat CLI – Configuration & Telemetry

Stage 1 provides a minimal, safe foundation:
- a legacy config loader (`BlackCat\\Cli\\Config\\CliConfig`) for proxy commands,
- telemetry writer (`CliTelemetry`),
- integration/security checks (`blackcat status`, `blackcat verify`).

Stage 2 adds manifest discovery (see below).

## CLI config file (Stage 1)

- default: `config/example.cli.php`
- override: `--cli-config=/path/to/cli.php` or `BLACKCAT_CLI_CONFIG`
- contains:
  - `commands` (proxy targets): runner (`php`/`node`), script, default args
  - `defaults.shopping_list` for `configure/install`
  - `config_profile` optional profile import (`blackcat-config` or local `config/profiles/*.php`)
  - `security.allowed_roots` allowlist for proxy targets

```php
use BlackCat\Cli\Config\CliConfig;

$config = CliConfig::fromFile();
$install = $config->command('install');
```

Note: `--config`/`--config-file` is reserved for **runtime config** (blackcat-config) and is forwarded to component tools that support it.

## Manifest discovery (Stage 2)

Component repositories can declare CLI capabilities in `blackcat-cli.json` at repo root.
`blackcat-cli` discovers manifests in the workspace (`blackcat-*/blackcat-cli.json`) and registers commands.

Manifests are validated by `blackcat-cli-spec`.

## Telemetry

`CliTelemetry` appends events to `var/cli-events.ndjson` and Prometheus metrics to `var/cli-metrics.prom`.

| Metric | Description |
| --- | --- |
| `blackcat_cli_command_total{command="install"}` | per-command invocation counter |

## Security + integrations

`blackcat status` prints configured/discovered commands.

`blackcat verify` (exit 2) ensures proxy targets exist and are inside allowed roots, and can also run doctor-style checks:
- runtime config validation via `blackcat-config` (when present; pass `--config=FILE` to force a specific JSON file)
- Prometheus target health (when `blackcat-monitoring` is present and Prometheus is reachable)

```bash
php bin/blackcat status --json
php bin/blackcat verify --json
php bin/blackcat verify --json --config=/etc/blackcat/config.runtime.json
```
