# BlackCat CLI – Configuration & Telemetry

Stage 1 přidává konfigurační loader `BlackCat\\Cli\\Config\\CliConfig`, který sjednocuje všechny binárky a bezpečnostní kontroly.

## Konfigurační soubor
- implicitně se načítá `config/example.cli.php` – přesměruj přes `BLACKCAT_CLI_CONFIG` nebo `--config`.
- obsahuje:
  - `commands` – runner (`php`/`node`), skript, defaultní argumenty.
  - `defaults.shopping_list` – fallback JSON pro `configure/install`.
  - `config_profile` – volitelný import env proměnných z `blackcat-config`/`config/profiles`.
  - `security.allowed_roots` – whitelisting cest, které smí CLI spouštět.

```php
$config = CliConfig::fromFile();
$install = $config->command('install');
```

## Telemetrie
`CliTelemetry` zapisuje události do `var/cli-events.ndjson` a Prometheus metriky do `var/cli-metrics.prom`.

| Metric | Popis |
| --- | --- |
| `blackcat_cli_command_total{command="install"}` | počet spuštění konkrétního příkazu za běh |

## Bezpečnost + integrace
`blackcat status` vypíše všechny proxované binárky, `blackcat verify` (exit 2) kontroluje, že cíle existují a leží v povolených složkách.

```bash
php bin/blackcat status --json | jq '.commands[] | select(.command=="install")'
php bin/blackcat verify
```

Soubor `config/profiles/example.profiles.php` slouží jako šablona pro centralizované profily sdílené v `blackcat-config`.
