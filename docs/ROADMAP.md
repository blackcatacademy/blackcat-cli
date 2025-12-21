# BlackCat CLI – Roadmap

## Stage 1 – Foundation ✅
- Config loader (`CliConfig`) + telemetry writer (`CliTelemetry`).
- `blackcat status` and `blackcat verify` for integration/security checks.
- Proxy commands via config (`install/configure/agent/crypto/observability/auth/db/governance/security`).
- Docs (`docs/CONFIG.md`) + smoke tests (`tests/test.cli`) + PHPUnit.

## Stage 2 – Manifest discovery (current)
- Adopt `blackcat-cli-spec` and discover `blackcat-cli.json` across installed components.
- Register builtin commands based on manifests (example: `blackcat db-crypto ...`).
- Keep proxy mode for transitional compatibility (while CLI logic is moved into this repo).

## Stage 3 – Backend modules
- Add native commands for orchestrator/sync pipelines and observability flows.
- Define stable CLI contracts and generate help/docs from manifests.
- CI hook: `blackcat verify --json` consumed by installers/agents.

## Stage 4 – Cross-ecosystem automation
- Wire `blackcat-cli` into installer/orchestrator pipelines for push-button deployments.
- Expand contract tests covering dependencies listed in `ECOSYSTEM.md`.
- Publish health/controls so observability, security, and governance can reason about CLI state.

## Stage 5 – Continuous AI augmentation
- Ship AI-ready manifests/tutorials enabling safe composition of stacks.
- Add self-healing + policy feedback loops leveraging governance signals.
- Feed anonymized adoption data to `blackcat-usage` and reward contributors via payout modules.
