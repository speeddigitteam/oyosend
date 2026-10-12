# Automation

State: Phase 10 backend. The contracts and registry below are consumed by the **Workflow Automation app**
(`app/Modules/Automation`, module key `automation`; full description in `docs/WORKFLOWS.md`), which stores versioned
JSON workflows, matches domain events to published workflows, runs them one persisted step at a time under the
member who published them, and re-checks permission and entitlement before every step. The visual editor (Vue Flow,
ADR 0012) ships with the app. Modules still own their definitions and their durable action-receipt tables; the engine
only calls the registered contracts.

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
| HRM definitions | Five triggers (employee created/terminated, leave requested/approved, attendance day closed) and the `hrm.leave.grant_balance` and `hrm.employee.assign_manager` actions under `app/Modules/Hrm/Automation` |
| HRM action receipts | `hrm_automation_action_receipts`, the same durable tenant/action/idempotency-key boundary; leave grants additionally carry the key into the append-only ledger |
| Payroll definitions | Three triggers (run approved, payslip paid, run reversed) and the `payroll.item.create` (pending item) and `payroll.run.prepare_draft` (draft run) actions under `app/Modules/Payroll/Automation`; approving, paying and reversing are deliberately not actions |
| Payroll action receipts | `payroll_automation_action_receipts`, the same durable tenant/action/idempotency-key boundary |
| Talent definitions | Nine triggers (candidate hired, application stage changed, interview scheduled, checklist completed, probation review due, review calibrated, training completed, certificate expiring, certificate expired) and the `talent.checklist.start` and `talent.course.enrol` actions under `app/Modules/Talent/Automation`; hiring, rejecting and calibrating are deliberately not actions |
| Talent action receipts | `talent_automation_action_receipts`, the same durable tenant/action/idempotency-key boundary; `talent.checklist.start` also derives the checklist's unique source key from the idempotency key |
| Support definitions | Six triggers (ticket created, assigned, customer replied, resolved, SLA breached, satisfaction submitted) and the `support.ticket.create`, `support.ticket.assign` and `support.ticket.add_note` actions under `app/Modules/Support/Automation`; replying to a customer, resolving, merging and closing are deliberately not actions |
| Support action receipts | `support_automation_action_receipts`, the same durable tenant/action/idempotency-key boundary; `support.ticket.create` and `support.ticket.add_note` also derive a unique `source_key` from the idempotency key, and `support.ticket.assign` advances the round-robin pointer only once per key |
| Communication definitions | Four triggers (message delivered, failed, bounced; contact unsubscribed) and the `communication.message.send` (transactional templates only; a marketing template is refused) and `communication.interaction.log` actions under `app/Modules/Communication/Automation` |
| Communication action receipts | `communication_automation_action_receipts`, the same durable tenant/action/idempotency-key boundary; the outbox message also derives its unique idempotency key from the action key, so a retry cannot send twice |
| Social definitions | Five triggers (post scheduled, approved, published, failed; account needs reauthorization) and the `social.post.create_draft` action under `app/Modules/Social/Automation`; the action only creates a draft, never submits, approves or schedules |
| Social action receipts | `social_automation_action_receipts`, the same durable tenant/action/idempotency-key boundary |
| SMS Marketing definitions | Two triggers (campaign launched, campaign completed) and the `sms_marketing.group.add_contact` action under `app/Modules/SmsMarketing/Automation`; the action adds an existing contact to a group and changes no consent. No action sends a message |
| SMS Marketing action receipts | `sms_marketing_automation_action_receipts`, the same durable tenant/action/idempotency-key boundary |
| Email Marketing definitions | Two triggers (campaign sent, subscriber subscribed) and the `email_marketing.subscriber.add_to_list` action under `app/Modules/EmailMarketing/Automation`; the action adds a contact to a list as `pending` (it can never record consent) and refusals are stored outputs |
| Email Marketing action receipts | `email_marketing_automation_action_receipts`, the same durable tenant/action/idempotency-key boundary |
| WhatsApp definitions | Four triggers (message received, message read, conversation assigned, campaign completed) and the `whatsapp_marketing.template.send` (an approved template through the communication gateway; a missing opt-in is a stored `blocked` output), `whatsapp_marketing.conversation.assign` and `whatsapp_marketing.conversation.add_tag` actions under `app/Modules/WhatsappMarketing/Automation`; trigger outputs carry ids and counts, never message text |
| WhatsApp action receipts | `whatsapp_automation_receipts`, the same durable tenant/action/idempotency-key boundary; the outbox message derives its idempotency key from the message row, so a retry cannot send twice |
| AI definitions | The `ai.proposal.confirmed` trigger and the `ai.text.generate` action (text only, no tools, needs the workspace `automation_enabled` setting) under `app/Modules/Ai/Automation` |
| AI action receipts | `ai_automation_action_receipts`, the same durable tenant/action/idempotency-key boundary; the token usage key derives from the idempotency key so a retry is neither regenerated nor metered twice |
| Engine triggers | `automation.trigger.manual`, `automation.trigger.webhook` and `automation.trigger.schedule` (`event()` is null: raised by the engine) under `app/Modules/Automation/Automation/Triggers` |
| Engine | `app/Modules/Automation` (`TriggerBridge`, `RunStarter`, `RunExecutor`, `Sweeper`, `GraphValidator`); tables `automation_*`; see `docs/WORKFLOWS.md` |
| Queue | `automation`, worked by Horizon's `background` supervisor; entitled Projects task maintenance dispatches here every minute; `ExecuteRun` and the per-minute `SweepRuns` of the Workflow Automation app run here too |

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
events include member/contact/company, billing and App Store lifecycle families. When the `automation` app is
entitled, `TriggerBridge` subscribes to the `event()` class of **every registered trigger** (also one registered
later) and starts the matching published workflows of the event's own tenant (`docs/WORKFLOWS.md` section 4).

### What the engine requires of a definition

- **Trigger**: `event()` must be the domain event class whose `payload()` carries the fields the trigger's
  `inputSchema()` filters on. The engine filters with a plain rule: a config key `minimum_x` means
  `payload.x >= value`; any other key must equal the payload value. Keep configuration keys equal to payload keys.
  Return `null` from `event()` only for triggers the engine raises itself (the three `automation.trigger.*`).
- **Action**: `execute()` is called with the validated input and the key `wf:{runId}:{stepId}`; it must be
  idempotent per key (use the module's receipt store) and must not rely on a signed-in user: the engine calls it in
  the tenant's context after re-checking `requiredPermission()` for the workflow's actor, for all records when the
  permission is scopable. An action that throws `ValidationException`, `ModelNotFoundException` or an
  authorization/4xx `HttpException` is **not retried**; any other exception is retried with backoff.
- `requiredPermission()` and `module()` are checked at publish and before every execution of the step; a revoked
  permission or an inactive app stops the run (`docs/WORKFLOWS.md` section 5).
- Outputs returned by `execute()` are available to later steps as `steps.<nodeId>.<key>`; keep them small.

### Registry additions for the engine

`AutomationRegistry::registeredTriggers()` / `registeredActions()` return every registered definition regardless of
the active tenant's entitlements (used to tell an unknown key from one the workspace lacks), and
`whenTriggerRegistered(Closure)` calls back for each trigger registered so far and later.

### Open items

- `label()` keys are translated from `automation.triggers` / `automation.actions` in the client catalogue (flat maps
  keyed by the registered key, both languages); a definition without an entry shows its key.
- Notification and message actions come from the Communication/Social/Support apps registering `ActionDefinition`s;
  the engine has no channel of its own. The AI app's `ai.text.generate` is an ordinary action node.

Table outline: `docs/DATABASE.md` (Automation section). Engine contract: `docs/WORKFLOWS.md`.
