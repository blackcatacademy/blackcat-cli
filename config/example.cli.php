<?php

$repoRoot = dirname(__DIR__);
$workspaceRoot = dirname(__DIR__, 2);

return [
    'workspace_root' => $workspaceRoot,
    'config_profile' => [
        'file' => __DIR__ . '/profiles/example.profiles.php',
        'environment' => getenv('BLACKCAT_ENV') ?: 'local',
        'name' => getenv('BLACKCAT_PROFILE') ?: null,
    ],
    'defaults' => [
        'shopping_list' => __DIR__ . '/shopping-list.example.json',
    ],
    'telemetry' => [
        'events_file' => $repoRoot . '/var/cli-events.ndjson',
        'metrics_file' => $repoRoot . '/var/cli-metrics.prom',
    ],
    'security' => [
        'allowed_roots' => [
            $workspaceRoot . '/blackcat-agent',
            $workspaceRoot . '/blackcat-install',
            $workspaceRoot . '/blackcat-auth',
            $workspaceRoot . '/blackcat-crypto',
            $workspaceRoot . '/blackcat-observability',
            $workspaceRoot . '/blackcat-database',
            $workspaceRoot . '/blackcat-governance',
            $workspaceRoot . '/blackcat-security',
        ],
    ],
    'commands' => [
        'configure' => [
            'runner' => 'node',
            'script' => getenv('BLACKCAT_INSTALL_CONFIGURE') ?: ($workspaceRoot . '/blackcat-install/bin/configure'),
            'args' => [],
        ],
        'install' => [
            'runner' => 'node',
            'script' => getenv('BLACKCAT_INSTALL_CLI') ?: ($workspaceRoot . '/blackcat-install/bin/install'),
            'args' => [],
        ],
        'agent' => [
            'runner' => 'php',
            'script' => getenv('BLACKCAT_AGENT_BIN') ?: ($workspaceRoot . '/blackcat-agent/bin/agent'),
            'args' => [],
        ],
        'crypto' => [
            'runner' => 'php',
            'script' => getenv('BLACKCAT_CRYPTO_BIN') ?: ($workspaceRoot . '/blackcat-crypto/bin/crypto'),
            'args' => [],
        ],
        'observability' => [
            'runner' => 'php',
            'script' => getenv('BLACKCAT_OBSERVABILITY_BIN') ?: ($workspaceRoot . '/blackcat-observability/bin/observability'),
            'args' => [],
        ],
        'auth' => [
            'runner' => 'php',
            'script' => getenv('BLACKCAT_AUTH_BIN') ?: ($workspaceRoot . '/blackcat-auth/bin/auth'),
            'args' => [],
        ],
        'db' => [
            'runner' => 'php',
            'script' => getenv('BLACKCAT_DB_BIN') ?: ($workspaceRoot . '/blackcat-database/bin/dbctl.php'),
            'args' => [],
        ],
        'governance' => [
            'runner' => 'php',
            'script' => getenv('BLACKCAT_GOVERNANCE_BIN') ?: ($workspaceRoot . '/blackcat-governance/bin/governance'),
            'args' => [],
        ],
        'security' => [
            'runner' => 'php',
            'script' => getenv('BLACKCAT_SECURITY_BIN') ?: ($workspaceRoot . '/blackcat-security/bin/security'),
            'args' => [],
        ],
    ],
];
