# ADR 0017 — Workflows are declarative data that run as the member who published them

- **Status:** Accepted
- **Date:** 2026-10-13
- **Phase:** 10 (Workflow Automation)

## Context

The spec asks for a central automation engine: triggers, conditions, actions, delays, approvals and webhooks, built
by workspace members in a visual editor. Three risks dominate. (1) A tenant must never get code execution: CLAUDE.md
forbids executing tenant-supplied code or expressions. (2) A worker has no signed-in user, so "who is this action
done by" must be answered explicitly, otherwise the engine becomes a super-identity that bypasses permissions and
record scopes. (3) A workflow can outlive the authority it was built under: delays last days, and roles, members and
app entitlements change in the meantime.

## Decision

1. **Declarative only.** A workflow is a versioned JSON graph of 13 node types (`trigger`, `condition`, `branch`,
   `action`, `delay`, `schedule`, `parallel`, `approval`, `webhook`, `transform`, `loop`, `error_handler`, `end`). It
   names triggers and actions that first-party modules registered in `AutomationRegistry` and supplies input. Nothing
   a tenant writes is evaluated as code: conditions use a closed operator registry (no regex, no expression
   operator), data mapping is a fixed list of operations, and a template is exactly `{{ dotted.path }}` into the run
   context with no operators or calls. Hard limits bound the graph and every run (`config/automation.php`: 100 nodes,
   250 edges, 256 KB graph, 200 steps per run, depth 5, 100 loop iterations, 10 parallel branches, 60-day run age,
   120 runs/minute per workflow, 600 per workspace, 20 concurrent runs).

2. **Publisher as actor.** A workflow runs as **one member: the person who last published (or resumed) it**
   (`automation_workflows.actor_membership_id`). There is no system identity. Whoever starts a run by hand is
   recorded but lends no permissions. Publishing requires the publisher to hold every trigger's and action's
   `requiredPermission()`, and a scopable permission must be held for `all` records because actions are not
   record-scoped.

3. **Re-checked before every step, nothing cached.** `ActorGuard::denial()` runs at publish and before every step:
   the workspace is entitled to `automation` and to the step's module, the actor's membership exists and is active,
   the actor still holds `automation.workflows.publish`, and holds the step's permission for all records. A denial
   (`permission_missing`, `permission_scope`, `module_not_entitled`, `automation_not_entitled`, `actor_inactive`,
   `actor_missing`) **fails the run without running the step and without routing to an error handler**. Role change,
   member removal, app deactivation or an expired subscription therefore stop the next step of every affected run,
   including runs that were waiting for days.

4. **Actions go through the modules.** An action runs through its registered `ActionDefinition::execute($input,
   $idempotencyKey)` with key `wf:{runId}:{stepId}`. A mutating action stores a tenant/action/idempotency-key
   receipt atomically with its change, so a retried step returns the original output. The engine never creates
   records itself and never bypasses a module's manager.

5. **One step at a time, persisted.** `RunExecutor` takes a lease on the run, executes at most one ready step and
   commits the step, its successors and the context in one transaction. Delays, retries, approval timeouts and cron
   schedules are `resume_at` timestamps woken by a per-minute sweeper (a scheduled job through the entitlement-gated
   runner), so nothing waits in memory and a deploy loses nothing.

6. **Humans decide approvals.** An approval node creates an `Approval`; only a member who holds
   `automation.approvals.decide`, is the named approver (or holds the named permission) and, by default, is not the
   person the run acts for, can decide. The decision is taken under a row lock and audited.

7. **Edges to the outside are narrow.** Inbound webhooks are HMAC-signed with a replay window; the URL token is
   stored as a SHA-256 and the signing secret is encrypted and shown once. Outbound calls go only to endpoints
   registered by `automation.webhooks.manage`, https/443, no credentials, host name only, every resolved address
   public, connection pinned to the approved addresses, no redirects, response capped. A workflow never carries a
   raw URL.

8. **Versions are immutable.** Publishing archives the previous published version and freezes the draft (SHA-256
   `graph_hash`); the model refuses to change a published row except to archive it. A run is pinned to its version.

## Consequences

- A workflow is exactly as powerful as its publisher. If the publisher loses a permission the workflow stops at its
  next step with a denial code; it does not degrade to a lower scope. This is deliberate and can surprise: a workflow
  built by an owner stops when the owner is removed or when their role is narrowed. There is no service account.
- Because scopable permissions must be held for `all`, a member with only `own` scope cannot publish workflows that
  use those actions.
- Workflows are not a way to elevate: an action a member could not perform by hand cannot be performed by their
  workflow.
- Granularity is one minute; sub-minute delays are not supported. Run history has no retention job. No listener
  turns `automation.run.failed` or `automation.approval.requested` into a notification yet.
- Anything the engine can do is bounded by the actions modules register. A module author must keep each action
  idempotent per key and keep money, approval and consent decisions out of actions (payroll approval, hiring,
  resolving a ticket and opting a contact in are deliberately not actions).
- `ResponseCap` reads `automation.limits.outbound_response_bytes` (64 KB; the class constant is the fallback when the
  key is unset or not positive).

## Alternatives considered

- **Run as a system identity with full access.** Rejected: bypasses permission and scope rules and turns every
  workflow author into an effective administrator.
- **Run as the workflow's creator, checked only at publish.** Rejected: authority would outlive a role change for
  days-long waits.
- **Per-workflow service accounts with their own grants.** Possible later; it adds a principal type, role editing
  and audit semantics that the product has not asked for.
- **A scripting or expression node.** Rejected: executing tenant-supplied expressions is forbidden.
- **A third-party workflow engine.** Rejected: a new dependency with its own tenancy and authorization model, and a
  larger trust surface, for a feature set the declarative engine covers.
