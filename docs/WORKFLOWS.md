# Workflows (Workflow Automation app)

The central automation engine of spec section 13, shipped as the optional first-party app `automation`
(category Workflow Automation, free offer, depends on `core`). It runs **versioned JSON workflows** whose triggers
and actions are the typed contracts every module registers in `AutomationRegistry` (see `docs/AUTOMATION.md`).
Nothing a tenant writes is ever executed as code: a workflow is data that selects registered, first-party
classes and supplies declarative input.

State: backend and interface are built. The visual editor is a Vue Flow canvas in `resources/js/Modules/Automation`
(`Pages/Workflows/Editor.vue`, `Components/EditorCanvas.vue`, `WorkflowNode.vue`, `RunGraph.vue`; ADR 0012). Our own
`lib/graph.ts` owns the graph rules and wire format and the server (`GraphValidator`) stays the authority; every
connection can also be made from the side panel without dragging, and below 768px the page shows a read-only outline
instead of the canvas. Pages for the list, a workflow, runs (with a status overlay on the executed graph), approvals and
webhooks sit beside it; section 10 is the contract they consume. There is no separate "AI action" node type: the AI app
registers `ai.text.generate` and any other app registers its actions the same way, and the editor offers them as
action nodes (section 14).

## 1. Concepts

| Thing | Table / class | Meaning |
|---|---|---|
| Workflow | `automation_workflows` / `Workflow` | A named automation: status, the member it runs as, and the live version pointer |
| Version | `automation_workflow_versions` / `WorkflowVersion` | One graph. `draft` is editable (at most one per workflow); `published` is an immutable snapshot with a SHA-256 `graph_hash`; `archived` is a retired snapshot |
| Run | `automation_runs` / `Run` | One execution of a version. All state to continue it is in the row and its steps |
| Run step | `automation_run_steps` / `RunStep` | One node execution (a token on the graph) with redacted input/output and an attempt log |
| Approval | `automation_approvals` / `Approval` | A human task created by an approval node |
| Webhook endpoint | `automation_webhook_endpoints` / `WebhookEndpoint` | An authorized outbound destination a webhook node may call |

Workflow status: `draft` (never published), `active` (a published version is live), `paused` (no new runs),
`archived`. **Only a published version of an `active` workflow starts live runs.** Editing never touches the live
version: saving creates or updates the single draft; publishing validates it strictly, archives the previous
published version, freezes the draft (`WorkflowVersion` refuses any later change to a published row except
archiving it) and makes it live.

A run is pinned to the version it started on. Publishing a new version does not alter runs in flight.
Archiving the workflow cancels them.

## 2. The graph (wire format)

Stored as JSON in `automation_workflow_versions.graph` (and sent by the editor). Unknown keys are dropped on save.

```json
{
  "schema_version": 1,
  "nodes": [
    { "id": "n1", "type": "trigger", "position": {"x": 0, "y": 0},
      "data": { "label": "Lead created", "config": { "trigger_key": "crm.lead.created", "config": {"minimum_score": 50} } } }
  ],
  "edges": [
    { "id": "e1", "source": "n1", "target": "n2", "sourceHandle": null, "data": {"loopBack": false} }
  ]
}
```

- `id`: `[A-Za-z0-9_-]{1,64}`, unique. `type`: one of the 13 below. `data.label` is free text (max 120).
- `sourceHandle` is the named output the edge leaves from; `null` is the unnamed default output.
- `data.loopBack: true` marks the single permitted kind of cycle: an edge back into a `loop` node.
- Limits: 100 nodes, 250 edges, 256 KB (`config/automation.php`).

### Node types, configuration and outputs (handles)

| `type` | `data.config` | Handles (`sourceHandle`) |
|---|---|---|
| `trigger` | `trigger_key` (registered trigger), `config` (the trigger's own filter, validated against its input schema). Exactly one per graph, no incoming edges | `null` |
| `condition` | `{match: "all"\|"any", rules: [Rule \| Group]}` (nested groups up to depth 4, 50 rules) | `true`, `false` |
| `branch` | `{cases: [{id, label?, match, rules}]}` 1 to 10 cases, first match wins | each case `id`, and `default` |
| `action` | `action_key`, `input` (object; values may be `{{ templates }}`), optional `retry: {max_attempts 1..5, backoff_seconds 1..3600}` | `null`, `error` |
| `delay` | `{amount: int, unit: "minutes"\|"hours"\|"days"}`, 1 minute to 30 days | `null` |
| `schedule` | `{mode: "cron", cron}` waits for the next occurrence in the workspace timezone, or `{mode: "at", at}` waits until a date-time (template allowed) | `null` |
| `parallel` | `{join_node?: nodeId}`; every unnamed outgoing edge is a branch (2 to 10) | `null` (one edge per branch) |
| `approval` | `{title, message?, approver: {type:"permission", permission} \| {type:"membership", membership_id}, prevent_self_approval (default true), timeout_hours (1..720, default 72), on_timeout: "approve"\|"reject"\|"fail"}` | `approved`, `rejected`, `error` |
| `webhook` | `{endpoint_id, payload (object, templates allowed), retry?}` calls a registered endpoint, POST JSON | `null`, `error` |
| `transform` | `{mappings: [{target, operation, source?, value?, values?}]}` writes `vars.<target>` | `null` |
| `loop` | `{items: "path"}` or `{count: n}`, `max_iterations` (<= 100) | `body`, `done` |
| `error_handler` | `{then: "resume"\|"fail"}` | `null` |
| `end` | `{status: "success"\|"failed", message?}`; no outgoing edges | none |

`Rule` = `{field: "trigger.amount", operator, value, value2?}`; `Group` = `{match, rules}`.

### Reading data: variables and templates

A run has one context: `trigger` (the event/webhook/manual payload), `steps.<nodeId>` (each node's output),
`vars` (transform results), `loop` (`item`, `index`, `count`), `error` (`node_id`, `code`, `message`), `run`
(`id`, `mode`, `source`, `started_at`), `workflow` (`id`, `name`), `approval` (last decision).

A **template** is exactly `{{ dotted.path }}` and nothing else: no operators, no calls, no evaluation. A string that
is only one template keeps the value's type; inside longer text the scalar's text is substituted. `field` of a rule,
`source` of a mapping and `items` of a loop take a bare path. Anything that is not a valid path is left as written.

### Condition operators (typed registry, `OperatorRegistry`)

`equals`, `not_equals`, `gt`, `gte`, `lt`, `lte`, `between` (value, value2), `contains`, `not_contains`,
`starts_with`, `ends_with`, `in`, `not_in` (list), `is_empty`, `is_not_empty`, `exists`, `not_exists`,
`is_true`, `is_false`, `before`, `after`, `within_last_days`, `within_next_days`. Each declares its value types
and arity (`GET editor` returns them). There is deliberately no regex and no expression operator.

### Data transformation (declarative mapping, `Transformer`)

Operations: `copy`, `literal`, `template`, `concat`, `lowercase`, `uppercase`, `trim`, `to_string`, `to_integer`,
`to_boolean`, `length`, `default`, `add`, `subtract`, `multiply`, `first`. Arithmetic is whole numbers only
(money is integer minor units); a float operand or an overflow fails the step.

## 3. Validation (`GraphValidator`)

Run on every draft save-as-you-go (`validate` route), by test runs, and strictly at publish. Output:
`{valid, issues: [{severity: "error"|"warning", code, message, node_id, edge_id}]}`.

Structural errors: `malformed`, `too_large`, `too_many_nodes`, `too_many_edges`, `no_trigger`, `many_triggers`,
`many_global_handlers`, `dangling_edge`, `self_edge`, `edge_into_trigger`, `edge_from_end`, `bad_loop_back`,
`bad_handle`, `duplicate_edge`, `cycle`, `unreachable`, `loop_body`, `loop_no_back`, `loop_back_outside`,
`nested_loop`, `parallel_in_loop`. Cycle check ignores only edges flagged `loopBack` that target a loop node and
start inside its body; loops cannot nest and cannot contain a parallel step. Reachability starts at the trigger
(and at an unconnected error handler).

Configuration errors per node (`bad_cron`, `bad_delay`, `unknown_operator`, `bad_title`, `bad_approver`,
`unknown_endpoint`, `bad_loop_count`, `bad_end`, ...). **Registry:** `unknown_trigger` / `unknown_action` for a key
nobody registered; action `input` is checked against its `inputSchema` (required keys, types, enums,
`additionalProperties: false`) for every value that is not a template (templates are checked at run time).
References to unknown variable roots or to a `steps.<id>` that does not exist are `bad_reference`.

**Permission and entitlement (publish = error, editing = warning):** for the trigger and every action,
`module_not_entitled`, `permission_missing`, `permission_scope` (a scopable permission held for less than
`all` records), `actor_inactive`, `automation_not_entitled`. See section 5.

Warnings only: `no_end` (a step with nothing after it simply ends the workflow), `inactive_endpoint`.

## 4. Triggers

| Kind | Key | How it starts |
|---|---|---|
| Domain event | the module's trigger key | `TriggerBridge` listens to the trigger's `event()` class (also triggers registered later). It looks only inside the event's own tenant, only at `active` workflows whose `trigger_key` matches, filters on the trigger config (`minimum_x` means `payload.x >= value`, any other key must equal the payload value) and starts a run per match |
| Manual | `automation.trigger.manual` | `POST workflows/{w}/run` (`automation.runs.start`) with a JSON payload |
| Webhook | `automation.trigger.webhook` | `POST automation/hooks/{token}` on the workspace host (section 7) |
| Schedule | `automation.trigger.schedule` | `config.cron`, evaluated in the workspace timezone; fired by the sweeper (section 6.4) |

The three engine triggers are registered like any module's, with `event() === null`.

**Idempotency.** `RunStarter` creates a run under a unique `(tenant_id, workflow_id, idempotency_key)`.
Event key: `evt:` + sha1 of event class, `occurredAt` (microseconds) and payload. Webhook key: a sender-chosen
`X-Oyosend-Delivery` id, else the signature. Schedule key: `sched:` + the slot's UTC minute. Manual and test keys
are ULIDs. A duplicate delivery returns the existing run and starts nothing.

**Loop prevention.** When an action raises a domain event that starts another workflow, the new run records
`parent_run_id` and `depth + 1` (`RunScope`). A run above `max_depth` (5) is created as `failed`
(`depth_limit`) and never executes.

## 5. Authorization: who a workflow runs as

A workflow runs as **one member: the person who last published (or resumed) it** (`workflows.actor_membership_id`).
There is no system super-identity and the engine never bypasses a module's own checks: actions still go through
the modules' managers and durable receipts (section 6.5).

- At **publish**, the publisher must hold every trigger's and action's `requiredPermission()` (and the workspace the
  module's entitlement). Scopable permissions must be held for `all`: actions are not record-scoped.
- **Before every step**, `ActorGuard::denial()` re-checks, with no cache from earlier steps: the workspace is
  entitled to `automation` and to the step's module; the actor's membership exists and is active; the actor still
  holds `automation.workflows.publish`; and, for a trigger or action node, its required permission (for `all`).
  A denial **fails the run without running the step and without routing to an error handler** (`permission_missing`,
  `permission_scope`, `module_not_entitled`, `automation_not_entitled`, `actor_inactive`, `actor_missing`).
- Changing a role, removing a member, deactivating or uninstalling an app, or an expired subscription therefore
  stops the very next step of every affected run, including runs that were waiting days.
- Whoever starts a run by hand is recorded (`started_by_membership_id`) but does not lend their permissions.

## 6. Execution

### 6.1 Step at a time

`RunStarter` creates the run and queues `ExecuteRun` (queue `automation`) after commit. `RunExecutor::advance()`:

1. takes the run's **lease** (`locked_until`, 120 s) in a transaction; a run whose lease is held, or a workspace at its
   concurrency limit (20 running), is left alone;
2. resets steps a dead worker left `running`, applies decided/expired approvals, wakes due delay/schedule waits;
3. executes at most **one** ready step: checks the actor (5), resolves templates, runs the node, and commits the
   step, its successors and the run context in one transaction;
4. returns whether another step is ready. `ExecuteRun` loops at most 10 steps and then re-queues itself.

The executor is the only writer of a run. Approval decisions, cancels and retries only set flags/rows and queue
the job. Run data is bounded: context 256 KB, step outputs 32 KB in context, log strings 2,000 characters.

### 6.2 Control flow

Parallel: one pending step per branch, scope-tagged; with `join_node` the join runs once when every branch has
arrived or ended; without one the branches simply finish. Loop: one body pass per item (`loop.item`,
`loop.index`), hard cap `min(max_iterations, 100)`; a larger list fails the run with `loop_limit` (never silently
truncated). Condition/branch route by handle. A run finishes when no step is pending or waiting: `succeeded`, or
`failed` when an `end` node with `status: failed` was reached or an error handler with `then: fail` ran.

### 6.3 Retries and errors

Actions and webhooks retry **retryable** failures (unexpected exceptions, 5xx/408/429) up to
`retry.max_attempts` (default 3, max 5) with exponential backoff from `retry.backoff_seconds` (default 30,
cap 3600). Not retried: validation errors from the action, not-found, authorization/4xx, invalid input,
authorization denials. When attempts are exhausted the failure follows the node's `error` edge, else the
workflow's unconnected error handler, else the run fails with the step's code. The attempt log is on the step.
`error_handler` nodes set `error.*` and either resume or fail the run after their chain. Per-step timeouts are
the job timeout (120 s) and a webhook call timeout (10 s); a run older than 60 days fails (`run_timeout`); an
approval has its own timeout.

### 6.4 Time: delays, schedules, the sweeper

Nothing waits in memory or in a queue payload. A delay or schedule node stores `resume_at` on its step; the app's
scheduled job `automation.sweep` (every minute, only for entitled workspaces, through the entitlement-gated
`apps:run-scheduled`) re-dispatches every run that is due: waiting runs past `resume_at`, queued runs that never
started, running runs whose lease expired (a crashed worker or a deploy), runs with a cancel request, runs with
a decided or expired approval; it also fires due cron schedules. So a delay of days, a retry backoff and an
approval timeout survive restarts and deploys; granularity is **one minute**. A missed schedule slot is skipped,
never replayed in a burst.

### 6.5 Actions and receipts

An action runs through its registered `ActionDefinition::execute($input, $idempotencyKey)` with the key
`wf:{runId}:{stepId}`: stable across retries of the same step, unique per loop iteration. Modules' durable
receipt tables (`*_automation_action_receipts`) make a retried step return the original output instead of
repeating the mutation. The engine never bypasses a module's authorization or creates records itself.

### 6.6 Limits (hard, `config/automation.php`)

`max_nodes` 100, `max_edges` 250, `max_steps_per_run` 200 (`step_limit`), `max_depth` 5, `max_loop_iterations` 100,
`max_parallel_branches` 10, `max_attempts` 5, `max_delay_days` 30, `max_run_age_days` 60, `runs_per_minute_workflow`
120 and `runs_per_minute_tenant` 600 (over the limit a trigger is dropped and logged, nothing is queued),
`concurrent_runs` 20, `max_payload_bytes` 64 KB, `max_context_bytes` 256 KB.

Plan quotas (`LimitDefinition`): `automation.runs` (metered; one record per live run, idempotency key
`automation:run:{runId}`, test runs excluded; over quota a run is recorded as failed `quota_exceeded`) and
`automation.workflows` (gauge: active workflows; checked at publish and resume).

## 7. Webhooks

### Inbound (trigger)

`POST {tenant}.APP_DOMAIN/automation/hooks/{token}` in the stateless `tenant.callback` group (no session, no
cookies, no CSRF; tenant from the hostname only). Publishing a workflow whose trigger is the webhook trigger
creates credentials, **shown once** (flash prop `revealed`): a URL token `whk_...` (stored only as SHA-256 hash; it
locates the workflow) and a signing secret `whsec_...` (stored encrypted, because HMAC verification needs it).
Rotate with `POST workflows/{w}/webhook/rotate`; the old pair stops working at once.

The caller sends JSON and headers `X-Oyosend-Timestamp` (unix seconds) and
`X-Oyosend-Signature: sha256=` + `hex(hmac_sha256(secret, timestamp + "." + rawBody))`, optionally
`X-Oyosend-Delivery` (<= 64 chars of `[A-Za-z0-9_-]`). Responses: `202 accepted`, `200 duplicate`, `401` bad/missing
signature or timestamp outside +-300 s (replay window), `404` unknown token/paused/not entitled (also another
workspace's token), `413` over 64 KB, `422` not a JSON object, `429` volume limit. A redelivered signed request is a
duplicate and starts nothing; the same bytes after the window are refused.

### Outbound (node)

A webhook node can only name an endpoint registered by someone holding `automation.webhooks.manage`; a workflow
never carries a raw URL. Endpoints: https only, port 443, no credentials, host name only (no IP literals), and
every address the name resolves to must be public (`UrlGuard`; private, loopback, link-local, reserved and
carrier-grade NAT refused, IPv4-mapped IPv6 judged as IPv4). The connection is pinned to the approved addresses,
redirects are not followed, timeout 10 s. The body is JSON, signed with the endpoint's secret in the same
`timestamp.body` scheme, with `X-Oyosend-Idempotency-Key`. The secret is write-only: never returned, logged or
audited.

## 8. Approvals

An approval node creates an `Approval` and the run becomes `awaiting_approval`. A member may decide it when they
hold `automation.approvals.decide`, are the named approver (or hold the named permission), and - when
`prevent_self_approval` is set (default) - are not the person the run acts for: whoever started it by hand, else
the member the workflow runs as. Decisions are recorded under a row lock (one decision wins), audited
(`automation.approval.approved|rejected`) and applied by the executor. `on_timeout` approves, rejects or fails the
step. Cancelling the run cancels pending approvals. The inbox lists only what the viewer may decide.

## 9. Test runs (dry run)

`POST workflows/{w}/test` executes the **draft** (or the published version when there is no draft) with a sample
payload in `mode: dry_run`: conditions, branches, loops and mappings are evaluated for real, but no action,
webhook or approval is performed, delays/schedules are skipped, nothing is metered and no domain event is raised.
Action input is still validated, so a test shows an input a real run would reject. The run stores a snapshot
of the graph it executed.

## 10. Server contract for the editor

Routes (all `{tenant}.APP_DOMAIN/automation/...`, `auth:web`, `member`, `subscribed`, `entitled:automation`):

| Route name | Method and path | Permission |
|---|---|---|
| `automation.workflows.index` | GET `workflows?search=&status=` | `automation.workflows.view` |
| `automation.workflows.store` | POST `workflows` (`name`, `description?`, `template?`) | `.manage` |
| `automation.workflows.show` | GET `workflows/{workflow}` | `.view` |
| `automation.workflows.update` | PUT `workflows/{workflow}` (`name`, `description`) | `.manage` |
| `automation.workflows.editor` | GET `workflows/{workflow}/editor` | `.view` |
| `automation.workflows.draft` | PUT `workflows/{workflow}/draft` (`graph`); JSON when `Accept: application/json` | `.manage` |
| `automation.workflows.validate` | POST `workflows/{workflow}/validate` (`graph?`), JSON | `.manage` |
| `automation.workflows.publish` | POST `.../publish` (`note?`) | `.publish` |
| `automation.workflows.pause` / `resume` / `archive` | POST | `.publish` |
| `automation.workflows.duplicate` | POST | `.manage` |
| `automation.workflows.versions.restore` | POST `.../versions/{version}/restore` | `.manage` |
| `automation.workflows.webhook.rotate` | POST | `automation.webhooks.manage` |
| `automation.workflows.test` | POST `.../test` (`payload`) | `.manage` |
| `automation.workflows.run` | POST `.../run` (`payload`) | `automation.runs.start` |
| `automation.runs.index` / `show` | GET `runs?status=&source=&workflow=&mode=`, `runs/{run}` | `automation.runs.view` |
| `automation.runs.cancel` / `retry` | POST | `automation.runs.manage` |
| `automation.approvals.index` / `decide` | GET `approvals`, POST `approvals/{approval}/decide` (`decision: approve\|reject`, `comment?`) | `automation.approvals.decide` |
| `automation.webhooks.index` / `store` / `update` / `destroy` | GET, POST, PUT, DELETE `webhooks[/{endpoint}]` | `automation.webhooks.manage` |
| `automation.hooks.receive` | POST `hooks/{token}` (stateless, signed) | none (HMAC) |

Inertia pages (`Modules/Automation/Pages/...`) and props:

- `Workflows/Index`: `workflows` (paginator of `{id, name, description, status, triggerKey, publishedVersionId,
  actor{id,name}, hasWebhook, nextRunAt, lastRunAt, lastRunStatus, publishedAt, updatedAt}`), `filters`, `statuses`,
  `templates[{key,name,description}]`, `usage{workflows,runs: {used, limit}}`, `abilities`.
- `Workflows/Show`: `workflow`, `versions[{id, number, status, hash, triggerKey, note, publishedBy, publishedAt,
  archivedAt}]`, `hasDraft`, `draftIssues`, `publishIssues`, `recentRuns`, `revealed {token, secret, url}|null` (once),
  `webhookPath`, `abilities`.
- `Workflows/Editor`: `workflow`, `version {id, number, status, graph, ...}`, `readOnly`, `issues`, `catalogue`
  (`triggers`/`actions` `[{key,label,module,kind,requiredPermission,inputSchema,outputSchema,allowed}]`,
  `nodeTypes[{type,label,handles,hasDefaultHandle,canBeTarget}]`, `operators[{key,label,types,arity}]`,
  `mappingOperations`, `variableRoots`, `limits`, `webhookEndpoints`, `members`, `approverPermissions`,
  `templates`), `publishIssues`, `abilities`. A failed publish redirects back with `errors.graph` and the full issue list in `publishIssues`.
- `Runs/Index`: `runs` (paginator), `filters`, `statuses`, `workflows`, `abilities`.
- `Runs/Show`: `run`, `payload` (redacted), `steps[{id,nodeId,nodeType,label,status,attempt,sequence,input,output,
  logs,errorCode,errorMessage,availableAt,resumeAt,startedAt,finishedAt}]`, `approvals`, `graph` (the executed
  snapshot for status overlay), `abilities {cancel, retry}`.
- `Approvals/Index`: `pending`, `history`. `Webhooks/Index`: `endpoints[{id,name,url,hasSecret,active,
  lastCalledAt,lastStatusCode}]`.

Client i18n keys the editor uses (present in both languages): `automation.nodes.<type>`,
`automation.operators.<key>`, trigger/action labels `automation.triggers.<key>` / `automation.actions.<key>`
(registered as `label()`), nav `automationWorkflows|automationRuns|automationApprovals|automationWebhooks`, the
limits `automation.runs` / `automation.workflows`, module `automation`, the eight permissions and the groups
`automation`, `automation_approvals`.

## 11. Permissions

`automation.workflows.view`, `.manage` (sensitive: edit drafts, test), `.publish` (sensitive: publish, pause,
resume, archive; the baseline permission a live workflow's actor must keep), `automation.runs.view`, `.start`,
`.manage` (cancel/retry), `automation.approvals.decide`, `automation.webhooks.manage` (sensitive). None is
scopable: workflows are workspace-level objects.

## 12. Audit and events

Audit entries: `automation.workflow.created|updated|published|paused|resumed|archived|restored|webhook_rotated`,
`automation.run.started|cancelled|retried`, `automation.approval.approved|rejected`,
`automation.webhook_endpoint.created|updated|deleted`. Domain events (ids and scalars, after commit):
`automation.workflow.published|archived`, `automation.run.finished|failed`, `automation.approval.requested|decided`.
`automation.run.failed` and `automation.approval.requested` are the hooks Phase 8 notifications use for failure
alerts and approver notifications; nothing here sends email or messages itself.

## 13. Operating notes

Run `php artisan permissions:sync` and `php artisan apps:sync --prune` on deploy (new app, permissions, limits).
Workers must process the `automation` queue (Horizon `background` supervisor). The sweeper needs
`apps:run-scheduled` to be scheduled every minute, as for every module. Uninstalling the app disables access and
stops runs at their next step (entitlement); workflows, versions and history are retained for reinstall.

## 14. Not built (owner decisions)

- A dedicated AI action node type (spec section 13): the AI app registers its actions as `ActionDefinition`s
  (`ai.text.generate`); they run like any other action (permission, entitlement, receipt, dry-run skip).
- Notification / email / SMS / WhatsApp actions: Phase 8 and later apps register them. The engine has no channel.
- Run history retention/pruning, per-workflow alert destinations and exporting a run are not built.
- Sub-minute delays: the sweeper granularity is one minute.
