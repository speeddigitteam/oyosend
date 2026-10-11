# Automation

State: after Phase 5. **There is no engine**: no workflows, builder, executions, workflow steps or logs. What
exists are the contracts and registry, plus module-owned action-receipt tables for durable retry safety. Core,
CRM and Projects register definitions now so the Phase 10 engine can consume stable module-owned contracts later (spec
section 27, note under the phase list).

## What exists today

| Piece | Where |
|---|---|
| Trigger contract | `app/Core/Automation/Contracts/TriggerDefinition.php` |
| Action contract | `app/Core/Automation/Contracts/ActionDefinition.php` |
| Registry | `app/Core/Automation/AutomationRegistry.php` (singleton) |
| Domain event contract | `app/Core/Events/DomainEvent.php`, base class `TenantDomainEvent` |
| Core trigger | `app/Core/Automation/Triggers/MemberJoinedTrigger.php` (`core.member.joined`) |
| CRM triggers | Lead created, lead qualified, deal won and activity completed under `app/Modules/CRM/Automation/Triggers` |
| CRM actions | Assign a lead, move a deal stage and create a follow-up activity under `app/Modules/CRM/Automation/Actions` |
| CRM action receipts | `crm_automation_action_receipts`, a tenant/action/idempotency-key unique result store used by mutating CRM actions |
| Projects definitions | Project/task lifecycle triggers and project/task actions under `app/Modules/Projects/Automation` |
| Projects action receipts | `project_automation_action_receipts`, the same durable tenant/action/idempotency-key boundary for project mutations |
| Invoicing definitions | Five invoice/payment/credit-note/overdue triggers and the `invoicing.invoice.create_draft` action under `app/Modules/Invoicing/Automation` |
| Invoicing action receipts | `invoicing_automation_action_receipts`, the same durable tenant/action/idempotency-key boundary |
| Queue | `automation`, worked by Horizon's `background` supervisor; entitled Projects task maintenance dispatches here every minute |

Definitions are metadata and callable contracts, not workflows. Registering CRM or Projects actions does not schedule or
execute them by itself; the Phase 10 engine remains responsible for workflow persistence, orchestration and runs. The
separate Projects maintenance job on the same queue creates due recurrences and emits reminder events; it does not execute
automation definitions.

Assign-lead and move-stage nevertheless enforce the action contract today: their mutation and receipt are one
transaction, and a retry in another process returns the previously stored output.

### Contracts

Both are metadata classes with static methods; a workflow will reference them by key and supply declarative
input, never code.

| Method | Trigger | Action | Meaning |
|---|---|---|---|
| `key(): string` | yes | yes | Stable id, prefixed with the module key: `crm.lead.created` |
| `label(): string` | yes | yes | Translation key for the builder |
| `module(): string` | yes | yes | Owning module; the tenant must be entitled to it |
| `requiredPermission(): ?string` | yes | yes | Permission needed to use it in a workflow |
| `inputSchema(): array` | yes | yes | JSON Schema of its configuration / input |
| `outputSchema(): array` | yes | yes | JSON Schema of the data handed to later steps |
| `event(): ?string` | yes | — | The `DomainEvent` class that fires it; null for triggers the engine raises itself (schedule, webhook, manual) |
| `execute(array $input, string $idempotencyKey): array` | — | yes | Runs for the active tenant. Must be idempotent per key, because the engine retries steps |

### Registration

A module lists class names in its manifest (`triggers`, `actions`). `ModuleServiceProvider::register()` passes
them to `AutomationRegistry`, which rejects a class that does not implement the contract, a key not prefixed
with `{module}.`, and a duplicate key (`tests/Unit/Modules/AutomationRegistryTest.php`).

### Domain events

Triggers are driven by domain events. Conventions (`DomainEvent`): past tense, `{module}.{entity}.{verb}`;
dispatched only after the surrounding transaction commits; payload of ids and scalars, never models. Existing
events include member/contact/company, billing and App Store lifecycle families. Nothing subscribes to them yet.

### Not wired yet

- Nothing executes definitions yet. The Phase 10 builder and runner must filter/check `module()` through
  `EntitlementChecker` and `requiredPermission()` through `AccessChecker` at selection **and** execution time.
  Deactivation, uninstall or expiry must stop future executions; stored workflow JSON is not an entitlement.
- `label()` keys have no entries in the client i18n catalogue.

## Planned (Phase 10)

From spec section 13, without design beyond what the spec states:

- Workflows stored as versioned JSON graphs; only published versions execute.
- Execution in queued jobs, one step at a time, with persisted state, so delays can last days and survive deploys.
- A visual builder on Vue Flow with trigger, condition, action, delay, schedule, branch, parallel, approval,
  webhook, AI action, data transformation, loop, error handler and end nodes.
- Step-level logs, retries, failure alerts, rate limits, per-tenant quotas, idempotency, timeouts, loop
  prevention (maximum steps per execution, maximum recursion depth), concurrency control.

Rules the spec fixes:

- Never execute tenant-supplied PHP, shell commands or code. Data transformation uses a safe, declarative
  expression language only.
- Every execution runs inside its tenant's context and checks the tenant's entitlement first.

Outline of the tables: `docs/DATABASE.md` section 4.
