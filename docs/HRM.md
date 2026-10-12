# HR Management

The fourth production optional app is `hrm` (name "HR Management", category HR & Payroll), implemented under
`app/Modules/Hrm` and registered by `HrmServiceProvider`. It covers **employees, attendance and leave**. Payroll
and Talent (recruitment, performance, training) are separate later apps that depend on it and read it only
through the contracts in `App\Core\Workforce` (see "Contracts for other modules").

The code ships with Oyosend; a workspace needs an effective plan or App Store entitlement for `hrm` before its
routes, navigation, permissions, automation definitions, widgets or scheduled jobs are usable. A workspace
without it receives 404 responses. Removing the entitlement hides the app and retains every record. The offer is
free (`included`); the demo Business and Enterprise plans include it.

State of the build: **backend and Vue pages complete (Phase 7, part A)**. The pages live in
`resources/js/Modules/Hrm` and render exactly the prop shapes below; strings are in
`Core/i18n/locales/{en,bn}/hrm.ts`. Leave units are shown as days (units / 2). Tables are prefixed `hrm_`.

## Identity: employee, membership, user

- `Employee` is the HR record. It is **not** a `User` and never binds one. `hrm_employees.owner_id` is the
  employee's optional `TenantMembership` (unique per tenant): "the person who can sign in as this employee".
  An employee without an account is perfectly valid (contractors, staff who never log in).
- Employee fields are personal/contact (name, emails, phone, date of birth, gender, national id encrypted at
  rest, address), organisation (`department_id`, `branch_id` reference the core Organization models;
  `designation_id` references `hrm_designations`; `manager_id` references another employee), employment
  (`employment_type`, `status`, `joined_on`, probation/confirmation dates, termination fields) and a costing
  hook (`cost_rate_amount` hourly in integer minor units + `cost_currency`; not a salary).
- Department and branch on the employee are the HR placement and are what `department` / `branch` permission
  scopes compare against. They are **not** kept in sync with the membership's own placement in core; HR owns
  both, and a person in "Sales" in the member list but "Ops" in HRM is scoped by HRM's value on HRM records.

## Employee numbers

`EmployeeNumbers` keeps one counter row per tenant (`hrm_number_sequences`, `UNIQUE(tenant_id, kind)`),
read `FOR UPDATE` and incremented **inside the creating transaction**: concurrent creators queue and a rollback
returns the number, so committed employees have no gaps. Format: `{prefix}{zero padded number}` (`EMP-0001`).
Prefix (max 20 chars: letters, digits, `- _ /`) and padding (1-10) are workspace settings and are not applied
retroactively.

## Lifecycle and history

Statuses: `probation`, `active`, `on_leave`, `suspended`, `terminated`. `EmployeeStatus::transitions()` is the
allowed graph (terminated can only be reinstated to `active`). Only `probation` and `active` are "working":
attendance days are generated for them and they can clock in and request leave.

| Rule | Detail |
|---|---|
| Suspended / terminated | A reason is required. Termination also needs `termination_type` and sets `terminated_on` (effective date) and `last_working_on` |
| Probation to active | Sets `confirmed_on` |
| Termination side effects | Direct reports move to the leaver's own manager (history rows); live leave requests that reach past `last_working_on` are cancelled with a ledger reversal |
| Reinstatement | Clears the termination fields and counts against the plan limit again |
| Reporting manager | Cycle-safe (walks the chain, depth 50, refuses a loop), must be an employee of the workspace who is not terminated |
| Self-service | Nobody changes their own status. A person without `all` scope of `hrm.employees.manage` cannot change their own department, branch, designation, manager, employment type, cost rate or account link (those fields are dropped); contact fields they may |

`hrm_employment_history` is an **immutable ledger**: one row per joined / status change / placement,
manager, type or cost change / account link, written in the same transaction as the change. The model throws on
update and delete. Nothing in the UI or API edits it.

Related records: `hrm_emergency_contacts` (max 10, one primary), `hrm_documents` (private files only through
`TenantStorage` under `hrm/{employee}/documents/`; 10 MB; pdf, doc(x), png, jpg, webp, txt; download needs
`hrm.personal.view` over the record or being that employee, sent as an attachment with `nosniff`),
`hrm_assets` (assignment/return, an asset tag can be held by one person at a time). Status and termination
reasons, history reasons and asset notes are shown only with `hrm.personal.view` over the record (or to the employee).
The manager and account pickers of the employee form are narrowed to the actor's `hrm.employees.view` scope, and a
member's email appears only with workspace-wide `hrm.personal.view`.

## Attendance

- `hrm_shifts`: local `HH:MM` start/end in the workspace timezone (an end at or before the start means the
  next day), break, late grace, early-leave grace, overtime threshold, optional half-day threshold, optional
  weekly-off days (otherwise the workspace's), one default shift. `hrm_shift_assignments` give an employee a
  shift from a date; a later assignment shortens the open earlier one to the day before; overlaps are refused.
  Without an assignment the default shift applies; without a shift the workspace work-day minutes (default 480).
- Weekly days off are ISO weekdays (1 Monday .. 7 Sunday; default `[5, 6]`). Public holidays
  (`hrm_holidays`, one per date, `public` or `optional`; only `public` is non-working) form the calendar.
- `hrm_attendance_entries` are clock-in/out intervals in UTC. **The server clock is the only authority**:
  clock endpoints accept no timestamp. At most one open entry per employee (a `UNIQUE(tenant_id, employee_id,
  open_marker)` on a nullable marker). Manual and regularized entries are entered in workspace-local time,
  must be in the past, ≤ 24 h, must not overlap another entry, and are audited.
- `hrm_attendance_days` is the computed daily record, unique per employee and local date.

### The minutes arithmetic (`AttendanceMath`)

Every instant is floored to the whole minute and all differences are integer minute counts: no float, no
rounding. The shift's wall-clock times are interpreted in the **workspace timezone on the work date**.

| Figure | Rule |
|---|---|
| `worked_minutes` | Sum of the merged closed intervals. The shift break is deducted only when the day has exactly one continuous entry longer than the break |
| `late_minutes` | `first_in - shift_start` when it is greater than the late grace, otherwise 0 (the whole delay is counted once the grace is exceeded) |
| `early_leave_minutes` | `shift_end - last_out` when greater than the early grace, otherwise 0 |
| `overtime_minutes` | `last_out - shift_end` when it meets the overtime threshold; on a weekly off or holiday everything worked is overtime |
| `status` | `holiday`, `weekly_off`, `on_leave` (two leave units), `present` / `half_day` (worked vs the half-day threshold, default half the scheduled minutes), `incomplete` (only an open entry), `absent` |

A clock-in in the tail (120 minutes) of the previous day's overnight shift belongs to the previous work date.

### Closing days

Scheduled job `hrm.attendance_close` (hourly, queue `automation`) runs `AttendanceCloser`: for every working
employee it closes each unclosed local date before today, at most 45 days back. Closing computes holidays,
weekly offs, approved leave and absences, sets `is_closed`, and announces `hrm.attendance.day_closed`. A closed
day is skipped, so reruns and overlapping workers do nothing twice. A day with an entry still open (an overnight
shift, less than 24 h old) is left for a later run. Changing a closed day (a manual entry, an approved
regularization, approved or cancelled leave) recomputes it and increments `revision`, which is announced again.

### Regularization

An employee requests a correction (own record, last 31 days, in/out times, reason; one pending per day). The
reporting manager (holding `hrm.attendance.approve`) or anyone holding it over the record's scope approves or
rejects; nobody decides their own, and whoever filed the request (for example HR on someone's behalf) cannot
decide it either. Approval records an entry with source `regularization` and recomputes the day.

### Devices

`App\Modules\Hrm\Contracts\AttendanceDevice` and `DevicePunch` describe the seam for a fingerprint/RFID
integration. **No implementation exists, nothing binds it, and there is no route or job for it.** A future
integration must authenticate the device, reject clock skew and hand punches to
`AttendanceManager::recordInstants(..., AttendanceSource::Device)`.

## Leave

- Units are integers: one working day is `LeaveUnits::PER_DAY` = **2 units**, a half day is 1. `LeaveUnits::toDays()`
  is display only.
- `hrm_leave_types`: paid or unpaid, `tracks_balance` (unpaid types usually do not), half days allowed, reason
  required, max consecutive days, minimum notice days, `approval_flow` (`manager_then_hr`, `manager_only`,
  `hr_only`).
- `hrm_leave_policies` (per leave type, optionally for given employment types; a policy naming the employee's
  type wins over a general one): accrual `annual` or `monthly`, annual units, carry-forward maximum, maximum
  balance, maximum units per request, **sandwich rule** (weekly offs and holidays between the first and last
  working day of a request count as leave), minimum service days.
- Leave year starts in `leave_year_start_month` (workspace setting). A request is charged to the leave year of
  its start date.

### Ledger

`hrm_leave_ledger` is **append-only** (the model throws on update/delete). A balance is the sum of `units`.
Row kinds: `accrual`, `carry_forward`, `carry_out`, `adjustment`, `request` (the reservation, negative),
`reversal` (rejection, cancellation). Every row has an `idempotency_key` unique per workspace
(`accrual:{employee}:{type}:{year}:annual|m{n}`, `request:{id}`, `reversal:{id}`, `carry_in|carry_out:...`,
`adjustment:{ulid|automation key}`), so a retried job or request posts nothing twice.

`LeaveAccruals` (job `hrm.leave_accrual`, daily, queue `automation`; also run for a new employee at creation):
`annual` grants the year's units at the start of the leave year, pro-rated by whole months when the employee joined
mid-year (`floor(annual * remainingMonths / 12)`); `monthly` grants month n `floor(annual*n/12) -
floor(annual*(n-1)/12)`, so the twelve months add up exactly; on the first run of a new year the previous year's
balance is closed (`carry_out`) and up to `carry_forward_max_units` is credited (`carry_forward`), the rest lapses;
`max_balance_units` caps accrual (a capped period still posts a zero row so it is not retried).
Manual adjustments (`hrm.leave.manage`) need a note and cannot take a balance below zero.

### Requests and approval

Submitting reserves the units (so pending requests count against the balance), refuses over-balance, overlapping
(at half-day precision), pre-joining, more-than-a-month-old, no-working-day, over-policy and notice/max-length
violations, and stores the per-day units (`days`: date => units) used by attendance and the calendar.

| Flow | Steps |
|---|---|
| `manager_then_hr` | `pending_manager` (the reporting manager, with `hrm.leave.approve`) then `pending_hr` (`hrm.leave.finalize` over the record) then `approved` |
| `manager_only` | `pending_manager` then `approved` |
| `hr_only` | `pending_hr` then `approved` |

A manager without a workspace account, or none, or a terminated one, sends the request straight to
`pending_hr`. A person holding `hrm.leave.finalize` over the record may decide a `pending_manager` request in
the manager's place (that decision completes it). Nobody decides their own request. Rejection needs a note.
Rejection and cancellation append a reversal. The decision routes require `hrm.leave.approve`; HR roles hold
both `approve` and `finalize`. Cancel: the employee while pending or before the start date; anyone with
`hrm.leave.manage` over the record at any time. Approving or cancelling recomputes closed attendance days.

## Reports

`HrmReports`: headcount (not terminated, by status, employment type, department, branch, designation; joiners
and leavers in a period), attendance totals per employee over closed days (SQL aggregates; ≤ 92 days),
leave granted / used / pending / balance per leave type and approved usage per department. The reports page is
workspace-wide (`hrm.reports.view`); the attendance summary page is narrowed by `hrm.attendance.view` scope.

## Contracts for other modules

Payroll and Talent must not import `App\Modules\Hrm\Models`. They declare `hrm` in their manifest
`dependencies` and use:

```php
App\Core\Workforce\Contracts\EmployeeDirectory   // find, findMany, findByMembership, list (cursor), directReports, managerChain
App\Core\Workforce\Contracts\EmployeeTimeSummary // attendance(employeeId, from, to), leaveUsage(employeeId, from, to)
App\Core\Workforce\Data\EmployeeSnapshot         // readonly: id, number, name, membershipId, work email, department/branch/designation
                                                 // ids + names, employmentType, status, managerId, joinedOn, terminatedOn,
                                                 // lastWorkingOn, costRateAmount, costCurrency,
                                                 // probationEndsOn, confirmedOn (added for Talent)
```

Both are bound to `WorkforceReader`, are tenant-scoped, system-level (not narrowed by a viewer's record scope: the
consumer applies its own access rules), return snapshots/arrays only and carry no personal data (no date of birth,
national id, address or documents). `EmployeeTimeSummary::attendance()` counts closed days only and reports
`open_days`; a payroll run should refuse a period with `open_days > 0`. `leaveUsage()` returns approved units per
leave type, clipped to the period, with `is_paid`.

**Projects timesheets (documented contract only).** Projects team identity is the `TenantMembership`. HRM exposes
`EmployeeDirectory::findByMembership($membershipId)` and every HRM event that concerns a person carries
`membership_id`, so a consumer can join project time to an employee without either module importing the other's
models. HRM does not read Projects tables, and nothing in HRM consumes `projects.time_entry.approved` today.

## Domain events and automation

Events (after commit; ids and scalars): `hrm.employee.created`, `hrm.employee.updated` (`changed` is a
comma-separated list of field names), `hrm.employee.terminated`, `hrm.leave.requested`, `hrm.leave.approved`,
`hrm.attendance.day_closed` (payload: `attendance_day_id, employee_id, membership_id, work_date, status,
scheduled/worked/late/early_leave/overtime minutes, leave_units, revision`). Triggers are registered for all but
`hrm.employee.updated`. Two actions with durable receipts (`hrm_automation_action_receipts`,
`UNIQUE(tenant_id, action, idempotency_key)`): `hrm.leave.grant_balance` (positive units only) and
`hrm.employee.assign_manager` (cycle-safe). Approving leave, status changes and clocking are deliberately **not**
actions.

## Permissions

| Permission | Scopable | Notes |
|---|---|---|
| `hrm.employees.view` | yes | list, show; the base record scope |
| `hrm.employees.create` | no | workspace-wide; may link an account |
| `hrm.employees.manage` | yes | edit, emergency contacts, documents, assets; linking an account needs `all` |
| `hrm.employees.lifecycle` | yes, sensitive | status changes and termination |
| `hrm.personal.view` | yes, sensitive | personal block, cost rate, emergency contacts, documents |
| `hrm.self.view` | no | the `me` self-service page |
| `hrm.attendance.view` | yes | day lists, employee month, summary, corrections list |
| `hrm.attendance.manage` | yes, sensitive | manual entries, shift assignment; never on one's own record unless `all` |
| `hrm.attendance.clock` | no | clock in/out for oneself |
| `hrm.attendance.regularize` | no | request a correction |
| `hrm.attendance.approve` | yes | decide corrections (also granted to the reporting manager) |
| `hrm.leave.view` | yes | requests, balances, calendar |
| `hrm.leave.request` | no | request leave (HR files for others with `hrm.leave.manage` too) |
| `hrm.leave.approve` | no | take part in approvals: the inbox and every decision route |
| `hrm.leave.finalize` | yes, sensitive | the HR step, and the manager step in the manager's place |
| `hrm.leave.manage` | yes, sensitive | file for others, adjust balances, cancel any request |
| `hrm.holidays.view` / `.manage` | no | holiday calendar |
| `hrm.reports.view` | no | workspace-wide reports and the HR dashboard widget |
| `hrm.settings.manage` | no, sensitive | numbering, calendar, designations, shifts, leave types and policies |

**Self-service is the `own` scope.** Records carry `owner_id` = the employee's membership, so a role granted
`hrm.employees.view`, `hrm.attendance.view` and `hrm.leave.view` with scope `own` sees exactly that person's
records. `department` / `branch` scopes compare the record's copied `department_id` / `branch_id`; closed
history keeps the team it happened in, open leave requests and corrections follow a move. Linking an account
re-owns that employee's existing records. The module does not use `HasRecordOwner` (its "creator becomes the
owner" hook is wrong when HR creates records for other people); `FollowsEmployee` copies the employee's
placement instead.

## Limits

`hrm.employees` (gauge): employees whose status is not `terminated`. Checked under a tenant row lock at creation
and at reinstatement.

## Audit

Models use `Auditable`; `national_id`, date of birth, personal email, phone, address, city, cost rate and currency,
notes, status and termination reasons, emergency-contact details and storage paths are excluded. Business events
are written inside the causing transaction: `hrm.employee.joined|status_changed`, `hrm.attendance.entry_recorded|
entry_deleted`, `hrm.regularization.approved|rejected|cancelled`, `hrm.leave.requested|manager_approved|approved|
rejected|cancelled|balance_adjusted`, `hrm.document.uploaded|removed`, `hrm.asset.assigned|returned`,
`hrm.settings.saved`.

## Routes

All under `{tenant}.APP_DOMAIN/hrm`, behind `auth:web`, `auth.session`, `member`, `subscribed`, `entitled:hrm`
and a `can:` permission (the record scope is applied again by the controller; an out-of-scope record answers 404).

| Name | Verb and path | Permission |
|---|---|---|
| `hrm.me` | GET `/me` | `hrm.self.view` |
| `hrm.employees.index` / `.create` / `.store` | GET `/employees`, GET `/employees/create`, POST `/employees` | view / create / create |
| `hrm.employees.show` / `.update` | GET, PUT `/employees/{employee}` | view / manage |
| `hrm.employees.status` | POST `/employees/{employee}/status` | lifecycle |
| `hrm.employees.contacts.store` / `.update` / `.destroy` | POST `/employees/{employee}/emergency-contacts`, PUT/DELETE `.../{contact}` | manage |
| `hrm.employees.documents.store` / `.destroy` | POST `/employees/{employee}/documents`, DELETE `.../{document}` | manage |
| `hrm.employees.documents.download` | GET `/employees/{employee}/documents/{document}` | view + personal.view (or self) |
| `hrm.employees.assets.store` / `.return` | POST `/employees/{employee}/assets`, POST `.../{asset}/return` | manage |
| `hrm.attendance.index` / `.summary` / `.employee` | GET `/attendance`, `/attendance/summary`, `/attendance/employees/{employee}` | attendance.view |
| `hrm.attendance.clock-in` / `.clock-out` | POST `/attendance/clock-in`, `/attendance/clock-out` | attendance.clock |
| `hrm.attendance.entries.store` / `.destroy` | POST `/attendance/entries`, DELETE `/attendance/entries/{entry}` | attendance.manage |
| `hrm.attendance.shift-assignments.store` | POST `/attendance/shift-assignments` | attendance.manage |
| `hrm.attendance.corrections.index` | GET `/attendance/corrections` | attendance.view |
| `hrm.attendance.corrections.store` | POST `/attendance/corrections` | attendance.regularize |
| `hrm.attendance.corrections.decision` | POST `/attendance/corrections/{correction}/decision` | attendance.approve |
| `hrm.attendance.corrections.cancel` | POST `/attendance/corrections/{correction}/cancel` | attendance.view |
| `hrm.leave.index` / `.balances` / `.calendar` | GET `/leave`, `/leave/balances`, `/leave/calendar` | leave.view |
| `hrm.leave.approvals` | GET `/leave/approvals` | leave.approve |
| `hrm.leave.requests.store` | POST `/leave/requests` | leave.request |
| `hrm.leave.requests.show` / `.cancel` | GET `/leave/requests/{leaveRequest}`, POST `.../cancel` | leave.view |
| `hrm.leave.requests.decision` | POST `/leave/requests/{leaveRequest}/decision` | leave.approve |
| `hrm.leave.adjustments.store` | POST `/leave/adjustments` | leave.manage |
| `hrm.holidays.index` | GET `/holidays` | holidays.view |
| `hrm.holidays.store` / `.update` / `.destroy` | POST `/holidays`, PUT/DELETE `/holidays/{holiday}` | holidays.manage |
| `hrm.reports.index` | GET `/reports` | reports.view |
| `hrm.settings.index` / `.update` | GET, PUT `/settings` | settings.manage |
| `hrm.settings.designations.store` / `.update` | POST `/settings/designations`, PUT `.../{designation}` | settings.manage |
| `hrm.settings.shifts.store` / `.update` | POST `/settings/shifts`, PUT `.../{shift}` | settings.manage |
| `hrm.settings.leave-types.store` / `.update` | POST `/settings/leave-types`, PUT `.../{leaveType}` | settings.manage |
| `hrm.settings.leave-policies.store` / `.update` | POST `/settings/leave-policies`, PUT `.../{policy}` | settings.manage |

## Pages the controllers render

Component names are `Modules/Hrm/Pages/<Name>`. Props are camelCase; dates are `Y-m-d`, instants ISO 8601 UTC.
Shared shapes: `employeeRow {id, number, name, firstName, lastName, status, employmentType, designation{id,name}|null,
department|null, branch|null, managerId, managerName, joinedOn, terminatedOn, workEmail, phone, hasAccount}`,
`day {id, date, status, scheduledMinutes, workedMinutes, lateMinutes, earlyLeaveMinutes, overtimeMinutes, firstInAt,
lastOutAt, entriesCount, leaveUnits, isClosed, revision}`, `leaveRequest {id, employee{id,name,number},
leaveType{id,name,code,isPaid}, startsOn, endsOn, startSession, endSession, units, days{date:units}, leaveYear,
status, reason, requestedAt, decidedAt, cancelledAt, cancellationReason, approvals[{step,decision,note,decidedAt}]}`
(listed rows add `abilities {decide, step, cancel}`).

| Page | Props |
|---|---|
| `Me/Show` | `hasProfile`, `employee`, `today`, `clock {clockedIn, since, day, shift, isWorkingDay, holiday}`, `month`, `days[]`, `leaveYear`, `balances[{leaveType, units}]`, `leaveTypes[]`, `sessions[]`, `requests[]`, `corrections[]`, `upcomingHolidays[]`, `abilities {clock, requestLeave, requestCorrection, viewLeave, viewAttendance}` |
| `Employees/Index` | `employees` (paginator of `employeeRow`), `filters`, `statuses`, `employmentTypes`, `departments`, `branches`, `canCreate` |
| `Employees/Create` | `form {departments, branches, designations, managers, members, employmentTypes, genders}`, `defaults` |
| `Employees/Show` | `employee` (row + probation/confirmation/status/termination fields, `membership`, `notes`, `costRateAmount`, `costCurrency`, `personal` or null), `reports[]`, `history[]`, `emergencyContacts[]`, `documents[]`, `assets[]`, `abilities {edit, editEmployment, changeStatus, viewPersonal, manageRecords, isSelf}`, `statusOptions[]`, `terminationTypes[]`, `form` or null |
| `Attendance/Index` | `date`, `today`, `rows` (paginator of `{employee, day, clockedIn}`), `totals{status:count}`, `statuses`, `filters`, `departments`, `abilities {manage, clock}` |
| `Attendance/Employee` | `employee`, `month`, `days[]`, `entries[]`, `holidays{date:name}`, `leave{date:units}`, `weeklyOff[]`, `assignments[]`, `totals`, `shifts[]`, `abilities {manage}` |
| `Attendance/Summary` | `month`, `from`, `to`, `rows` (paginator of per-employee totals), `filters`, `departments` |
| `Attendance/Corrections` | `corrections` (paginator, rows add `abilities {decide, cancel}`), `status`, `canRequest`, `windowDays`, `today` |
| `Leave/Index` | `requests` (paginator), `filters`, `types[]`, `abilities {request, manage, approve}`, `sessions[]`, `today` |
| `Leave/Approvals` | `requests` (paginator) |
| `Leave/Show` | `request` (with `approvals`), `balanceUnits` |
| `Leave/Balances` | `year`, `rows` (paginator of `{employee, balances{typeId:units}}`), `types[]`, `canAdjust` |
| `Leave/Calendar` | `month`, `from`, `to`, `requests[]`, `holidays{date:name}`, `weeklyOffDays[]` |
| `Holidays/Index` | `year`, `holidays[{id,name,date,type}]`, `canManage` |
| `Reports/Index` | `from`, `to`, `filters`, `headcount`, `attendance` (paginator), `leave {year, by_type[], by_department[]}`, `departments`, `maxDays` |
| `Settings/Index` | `settings`, `designations[]`, `shifts[]`, `leaveTypes[]`, `leavePolicies[]`, `options {employmentTypes, accruals, approvalFlows}` |

Dashboard widgets: `hrm.self` (`HrmSelfWidget`, `hrm.self.view`: `hasProfile, clockedIn, leaveBalanceUnits,
pendingRequests, canClock`) and `hrm.overview` (`HrmOverviewWidget`, `hrm.reports.view`: `headcount,
onProbation, onLeaveToday, clockedInNow, pendingLeave, pendingCorrections`).

## Deployment

Run migrations (`app/Modules/Hrm/Database/Migrations`), then `php artisan permissions:sync` and
`php artisan apps:sync --prune`. New permissions are granted to no existing role; owners hold them implicitly.
Seed leave types, policies, a default shift and the holiday calendar in the settings pages: nothing is created
for a workspace automatically.

## Not built

Attendance device integrations (interface only), payroll and talent (separate apps), leave
encashment, shift rosters/rotations, geofenced or IP-restricted clocking, optional-holiday quotas, public
holiday templates per country, notification delivery (Phase 8), document expiry reminders, and importing
employees from a file.
