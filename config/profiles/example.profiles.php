<?php

return [
    [
        'name' => 'local',
        'environment' => 'local',
        'env' => [
            'BLACKCAT_INSTALL_CONFIGURE' => '/usr/local/bin/blackcat-configure',
            'BLACKCAT_INSTALL_CLI' => '/usr/local/bin/blackcat-install',
            'BLACKCAT_AGENT_BIN' => '/usr/local/bin/blackcat-agent',
        ],
    ],
    [
        'name' => 'ci',
        'environment' => 'ci',
        'env' => [
            'BLACKCAT_CLI_TELEMETRY' => '/tmp/blackcat-cli-metrics.prom',
        ],
    ],
];
