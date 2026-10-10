# Automation

State: end of Phase 1. **There is no engine**: no workflows, no builder, no executions, no tables. What exists are
the contracts and the registry, so that every module built from now on registers its triggers and actions from
its first day (spec section 27, note under the phase list). The engine is Phase 10.

## What exists today

| Piece | Where |
|---|---|
| Trigger contract | `app/Core/Automation/Contracts/TriggerDefinition.php` |
| Action contract | `app/Core/Automation/Contracts/ActionDefinition.php` |
| Registry | `app/Core/Automation/AutomationRegistry.php` (singleton) |
| Domain event contract | `app/Core/Events/DomainEvent.php`, base class `TenantDomainEvent` |
| First trigger | `app/Core/Automation/Triggers/MemberJoinedTrigger.php` (`core.member.joined`) |
| Queue | `automation`, worked by Horizon's `background` supervisor; nothing dispatches to it |

No action is registered yet.

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
events: `core.member.invited`, `core.member.joined`, `core.member.removed`. Nothing subscribes to them yet.

### Not wired yet

- Nothing checks `module()` against entitlements or `requiredPermission()` against the user, because nothing
  builds or runs workflows.
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
