# Project management

The production optional app `projects` is implemented under `app/Modules/Projects` and registered by
`ProjectsServiceProvider`. Its code and migrations ship with Oyosend, but a workspace needs an effective plan or
App Store entitlement before its project routes, navigation or dashboard data are usable. Permissions and
automation definitions remain compiled catalogue metadata; every current or future execution path must still
check the workspace entitlement. Removing that entitlement hides the app and retains its business records for a
later reinstall.

## Identity and access

Projects reuse core identities rather than creating parallel people or customer tables:

- a client is a shared core `Contact` and/or `Company`;
- an internal owner, member, assignee, watcher, approver or time-sheet worker is a `TenantMembership`, never a
  global `User`;
- project and task scope columns use the normal owner/department/branch model and `RecordAccess`;
- contact merge participates through core's `ContactMergeReference` extension so project and client-portal
  references follow the canonical contact transactionally.

Every project table is tenant-owned, uses ULIDs and has tenant-first indexes. Nested records are validated against
their parent project as well as tenant scope, so a valid identifier from another project cannot be substituted.
Signed-in project routes layer tenant resolution, entitlement, authentication, active membership, permission and
record scope; an inaccessible record answers 404. The two public portal routes replace member authentication with
the current invitation capability (stored only as a hash) while retaining tenant, entitlement, expiry, grant and
record checks.

## Planning and delivery

A project has a category, status, priority, schedule, owner, internal members and optional shared client. Reusable
templates copy task blueprints into a new project without keeping mutable links to the template. Tasks support
subtasks, ordered checklists, multiple assignees and watchers, milestones, sprints, reminders and recurrence.

The same task data drives list, Kanban, calendar and Gantt views. Moving a task is a normal server-authorized
status/order update; calendar, Gantt and reminder inputs use the workspace timezone and are persisted in UTC.
Recurring schedules advance in the workspace's local calendar so daily and weekly wall times survive daylight-
saving changes, and each generated occurrence carries its correspondingly shifted reminder. Task dependencies are project-local and
cycle-checked before insertion. Completing work writes activity history and emits the module's domain event only
after the transaction commits. Recurrence creates a distinct next task through an idempotent boundary rather
than reopening completed history.

Comments support explicit membership mentions. Attachments are private tenant files stored through
`TenantStorage`; the database holds only a generated path and metadata, and download authorization is inherited
from the project/task. Watchers and mentions are durable intent records; the Phase 8 notification system can
consume those records alongside the existing reminder event without this module pretending to deliver email, SMS
or realtime messages today.

The project workspace exposes these workflows directly: managers can maintain the project team, members can
watch tasks and participate in authorized comment threads, editors can add checklist items and dependencies, and
project/task attachments retain their record-scoped upload, download and deletion rules. Task details expose
multi-assignee allocation, including explicitly clearing every assignee, while project lifecycle controls show
only server-valid status transitions and confirm terminal changes.

## Time, resources and money

Time entries belong to a project, a `TenantMembership` and optionally a task. Minutes are integers, timer
timestamps are validated as an ordered pair, and entries follow a submitted/approved workflow. Workload and
resource allocation are derived from project membership, task assignment, allocation percentages and scheduled
minutes. The future HRM module may consume the documented time-sheet contract; Projects does not import an HRM
model or invent an employee table.

Budgets, expenses, billable rates and cost rates are integer minor units with an ISO currency. No decimal or
floating-point value is authoritative. Profitability is the project's budget and approved cost/billable facts in
this module. Customer invoice revenue remains a Phase 6 concern and must arrive through a documented event or
service contract; Projects never queries a future Invoicing model.

## Approvals, reporting and client collaboration

Approval requests reference a supported project entity and an active workspace approver. An authorized requester
may cancel their own pending request; an eligible decider may cancel one stranded by an approver who is no
longer available.
Approved and rejected decisions retain immutable decision metadata. Reports and the dashboard derive status,
due-date, workload, time, expense and budget summaries from tenant-scoped rows.

The report's optional From/To dates are an inclusive delivery window: projects and tasks are included when their
planned ranges overlap it, while approved time and expense facts are included when their worked/incurred date is
inside it. A missing project or task range endpoint is open-ended; the project's budget itself remains the full
approved budget rather than being prorated across the selected window.
Task, milestone, submitted time and submitted expense rows expose approval-request state without replacing the
separate approver decision queue. Milestone and sprint controls only offer transitions accepted by their locked
domain state machines.

Client collaboration uses an explicit `project_client_access` grant tied to the project's shared contact. Invite
tokens are random, stored only as SHA-256 hashes, expire, and grant only the published project summary and allowed
client actions. Internal comments and financial fields stay hidden unless their dedicated flags permit them.
Revoking the grant takes effect server-side; a client is not silently converted into a workspace account.

## App Store and automation

The compiled manifest publishes the app's catalogue metadata, free included offer, permissions, route inventory,
navigation, dashboard widget, model morph aliases and project/task automation definitions. Mutating automation
actions store their output in `project_automation_action_receipts` under a unique tenant/action/idempotency key in
the same transaction as the mutation. A retry returns that receipt instead of applying the change twice.

Separately, the manifest schedules `projects.task_maintenance` every minute. The App Store scheduler checks the
entitlement before dispatch and its queued wrapper checks it again at worker time; the job creates due recurrence
instances through their idempotency key and emits each due reminder once.

Phase boundaries are explicit:

- CRM may request project creation only through the documented Projects action/event boundary; Projects does not
  import CRM models.
- Invoicing can later provide recognized project revenue through a contract/event boundary.
- HRM can later consume approved time and resource facts through a contract/event boundary.
- Phase 8 can turn reminder events plus durable mention and watcher records into notifications.

Deployments run migrations, `php artisan permissions:sync` and `php artisan apps:sync --prune`. New project
permissions are not silently granted to existing roles; owners retain their normal implicit access and operators
must deliberately update other roles.

## Important contracts

- Tenant ids and calculated totals never come from request data.
- Client and team identities always point to core records belonging to the active tenant.
- Project/task dependencies stay inside one project and cannot form a cycle.
- Money is integer minor units, duration is integer minutes, allocation/progress are bounded integers, and stored
  datetimes are UTC.
- Files and portal tokens are private capabilities checked against current project access on every request.
- Module-to-module collaboration uses events or documented contracts, never another module's Eloquent models.
