# BlackCat CLI Suite

Monorepo pro jednotnou CLI aplikaci `blackcat` (alias `bc`), která sjednocuje správu všech BlackCat komponent – databáze, auth, orchestrátor, governance, observability. Cílem je mít jediný entrypoint pro vývojáře i DevOps:

- `blackcat auth ...` – správa klientů, audit hooků, passkey registrace.
- `blackcat db ...` – instalace, migrace, snapshoty, CDC orchestrace.
- `blackcat sync ...` – ovládání `blackcat-database-sync` pipeline.
- `blackcat crypto ...` – rotace klíčů, KMS diagnostika.
- `blackcat observability ...` – tailování eventů, export metrik.
- `blackcat orchestrator ...` – spouštění workflow, sledování jobů.
- `blackcat governance ...` – politika-as-code, audity.

## Stav

Repo je po Stage 1 upgradu – obsahuje konfigurovatelný loader (`CliConfig`), telemetrii (`CliTelemetry`), bezpečnostní check (`blackcat verify`) a proxy na hlavní backend CLI. Další vývoj naváže na Stage 2+ roadmap (rozšíření backend modulů, AI workflow). CLI bude distribuováno jako PHP Phar i Node CLI (přes `blackcat-auth-js`).

## Použití

```bash
# interaktivní konfigurace shopping listu (env hodnoty)
php bin/blackcat configure shopping-list.json

# spustí instalaci (volá blackcat-install -> blackcat-installer)
php bin/blackcat install shopping-list.json

# proxy na ostatní CLI
php bin/blackcat crypto metrics:export prom
php bin/blackcat observability events:tail
php bin/blackcat agent template shopping-list
php bin/blackcat auth help
php bin/blackcat db --help
php bin/blackcat governance policy:list
php bin/blackcat security checklist stride
```

CLI používá interně `blackcat-install/bin/configure` a `bin/install`. Cesty lze přepsat proměnnými `BLACKCAT_INSTALL_CONFIGURE` a `BLACKCAT_INSTALL_CLI`.

Proxy příkazy respektují proměnné `BLACKCAT_CRYPTO_BIN`, `BLACKCAT_OBSERVABILITY_BIN`, `BLACKCAT_AGENT_BIN`, `BLACKCAT_AUTH_BIN`, `BLACKCAT_DB_BIN`, `BLACKCAT_GOVERNANCE_BIN`, `BLACKCAT_SECURITY_BIN`.

## Konfigurace
- implicitně se načítá `config/example.cli.php` – přesměruj přes `BLACKCAT_CLI_CONFIG` nebo `--config`.
- struktura souboru obsahuje `commands`, `defaults.shopping_list`, `telemetry` a `security.allowed_roots`.
- Profile loader (`config_profile`) umí načíst sdílené profily z `blackcat-config` nebo lokálního `config/profiles/*.php`.

```php
$config = BlackCat\Cli\Config\CliConfig::fromFile();
$install = $config->command('install');
```

## Telemetrie & bezpečnost
- Každé spuštění zapisuje event do `var/cli-events.ndjson` + Prometheus metriky do `var/cli-metrics.prom`.
- `blackcat status [--json]` vypíše připojené binárky (cesta, runner, povolení).
- `blackcat verify [--json]` provede security/integration check (exit 2 při chybě) a hodí se pro CI.

## Testy

```bash
bash tests/test.cli
php tests/ConfigTest.php
```
