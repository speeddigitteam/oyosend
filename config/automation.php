<?php

/*
|--------------------------------------------------------------------------
| Workflow automation limits
|--------------------------------------------------------------------------
|
| Hard, code-level safety limits of the workflow engine (docs/WORKFLOWS.md).
| They exist to stop a mis-built or malicious workflow from consuming the
| platform: every one is enforced by the server at publish time, run start
| or execution time, never by the builder. Plan quotas (runs per month,
| active workflows) are separate and come from the billing limits.
|
*/

return [

    'limits' => [
        // Graph size.
        'max_nodes' => 100,
        'max_edges' => 250,
        'max_graph_bytes' => 262144,
        'max_condition_depth' => 4,
        'max_condition_rules' => 50,
        'max_mappings' => 50,

        // Execution.
        'max_steps_per_run' => 200,
        'max_depth' => 5,                 // workflow -> event -> workflow chains
        'max_loop_iterations' => 100,
        'max_parallel_branches' => 10,
        'max_attempts' => 5,
        'max_backoff_seconds' => 3600,
        'max_delay_days' => 30,
        'max_run_age_days' => 60,
        'run_lease_seconds' => 120,       // a worker holds a run this long before another may take it

        // Volume.
        'runs_per_minute_workflow' => 120,
        'runs_per_minute_tenant' => 600,
        'concurrent_runs' => 20,

        // Data.
        'max_payload_bytes' => 65536,     // inbound webhook / manual payload
        'max_context_bytes' => 262144,    // persisted run context
        'max_log_string' => 2000,

        // Approvals.
        'approval_default_hours' => 72,
        'approval_max_hours' => 720,

        // Webhooks.
        'webhook_replay_seconds' => 300,
        'outbound_timeout_seconds' => 10,
        'outbound_response_bytes' => 65536,
    ],

];
