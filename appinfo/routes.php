<?php

declare(strict_types=1);

/**
 * Route definitions for the Nextcloud Migrate app.
 *
 * All endpoints are admin-only (enforced in the controllers) since v1 is an
 * admin-driven multi-user migration tool.
 */
return [
    'routes' => [
        // Remote instance (target) credential management
        ['name' => 'migration#listInstances', 'url' => '/api/v1/instances', 'verb' => 'GET'],
        ['name' => 'migration#createInstance', 'url' => '/api/v1/instances', 'verb' => 'POST'],
        ['name' => 'migration#testInstance', 'url' => '/api/v1/instances/{instanceId}/test', 'verb' => 'POST'],
        ['name' => 'migration#deleteInstance', 'url' => '/api/v1/instances/{instanceId}', 'verb' => 'DELETE'],
        ['name' => 'migration#listRemoteUsers', 'url' => '/api/v1/instances/{instanceId}/remote-users', 'verb' => 'GET'],

        // User mapping helpers
        ['name' => 'migration#listLocalUsers', 'url' => '/api/v1/local-users', 'verb' => 'GET'],

        // Migration run lifecycle
        ['name' => 'migration#listRuns', 'url' => '/api/v1/runs', 'verb' => 'GET'],
        ['name' => 'migration#createRun', 'url' => '/api/v1/runs', 'verb' => 'POST'],
        ['name' => 'migration#getRun', 'url' => '/api/v1/runs/{runId}', 'verb' => 'GET'],
        ['name' => 'migration#dryRun', 'url' => '/api/v1/runs/{runId}/dry-run', 'verb' => 'POST'],
        ['name' => 'migration#approveRun', 'url' => '/api/v1/runs/{runId}/approve', 'verb' => 'POST'],
        ['name' => 'migration#pauseRun', 'url' => '/api/v1/runs/{runId}/pause', 'verb' => 'POST'],
        ['name' => 'migration#resumeRun', 'url' => '/api/v1/runs/{runId}/resume', 'verb' => 'POST'],
        ['name' => 'migration#cancelRun', 'url' => '/api/v1/runs/{runId}/cancel', 'verb' => 'POST'],

        // Status / reporting
        ['name' => 'status#runStatus', 'url' => '/api/v1/runs/{runId}/status', 'verb' => 'GET'],
        ['name' => 'status#runFiles', 'url' => '/api/v1/runs/{runId}/files', 'verb' => 'GET'],
        ['name' => 'status#runFailures', 'url' => '/api/v1/runs/{runId}/failures', 'verb' => 'GET'],
        ['name' => 'status#runReport', 'url' => '/api/v1/runs/{runId}/report', 'verb' => 'GET'],
        ['name' => 'status#runEvents', 'url' => '/api/v1/runs/{runId}/events', 'verb' => 'GET'],
    ],
];
