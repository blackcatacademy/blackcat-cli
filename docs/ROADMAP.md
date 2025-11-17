# BlackCat CLI – Roadmap

## Stage 1 – Foundation ✅
- Konfigurační loader (`CliConfig`) + telemetry writer (`CliTelemetry`).
- `blackcat status` a `blackcat verify` hlídají integrace/sandbox.
- Proxy příkazy sjednocené přes config (install/configure/agent/crypto/observability/auth/db/governance/security).
- Dokumentace (`docs/CONFIG.md`) + testy (`tests/test.cli`, `tests/ConfigTest.php`).

## Stage 2 – Backend modules
- Rozšířit CLI o orchestrator/sync/observability pipelines (prefetch schémata, orchestrátor jobs).
- Přidat commands jako `blackcat modules publish`, `blackcat governance diff` v rámci jednotného `commands` mapu.
- CI hook `blackcat verify --json` + `blackcat status` integrace do installer/agent checků.

## Stage 3 – AI/Workflow integrace
- GitHub Action, která spouští `blackcat-agent` -> `blackcat CLI` -> report do PR.

## Stage 4 – Cross-Ecosystem Automation
- Wire blackcat-cli services into installer/orchestrator pipelines for push-button deployments.
- Expand contract tests covering dependencies listed in ECOSYSTEM.md.
- Publish metrics/controls so observability, security, and governance repos can reason about blackcat-cli automatically.

## Stage 5 – Continuous AI Augmentation
- Ship AI-ready manifests/tutorials enabling GPT installers to compose blackcat-cli stacks autonomously.
- Add self-healing + policy feedback loops leveraging blackcat-agent, blackcat-governance, and marketplace signals.
- Feed anonymized adoption data to blackcat-usage and reward contributors via blackcat-payout.
