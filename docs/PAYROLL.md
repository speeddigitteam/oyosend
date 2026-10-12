# Payroll

The fifth production optional app is `payroll` (name "Payroll", category HR & Payroll), implemented under
`app/Modules/Payroll` and registered by `PayrollServiceProvider`. It covers **salary structures, immutable salary
revisions, pay periods, payroll runs with four-eyes approval, payslips, one-off pay items, loans and advances,
configurable tax/contribution rule sets, payment tracking and payroll reports**. It depends on `core` and `hrm` and
reads HR data only through the contracts in `App\Core\Workforce` (see [HRM.md](HRM.md)): it imports no
`App\Modules\Hrm` model and no model of any other optional module.

The code ships with Oyosend; a workspace needs an effective plan or App Store entitlement for `payroll` (and for
`hrm`, its dependency) before its routes, navigation, permissions, automation definitions or widgets are usable. The
offer is free (`included`); the demo Business and Enterprise plans include it. Removing the entitlement hides the app
and retains every record.

State of the build: **backend and Vue pages complete (Phase 7)**; the page names and prop shapes below are what
the controllers render (pages under `resources/js/Modules/Payroll/Pages`). Tables are prefixed `payroll_`.

## What it deliberately is not

- It **does not move money**. A person pays outside Oyosend (bank sheet, mobile wallet, cash) and records the
  outcome; no gateway, bank API or payout is integrated.
- It ships **no tax or contribution law**. Rule sets are data the workspace enters (see "Rule sets"); nothing is
  preloaded and no country's slabs are encoded. The only slab tables in the repository are labelled EXAMPLE and live
  in tests.
- It has no PDF library: the payslip is print-optimised HTML and the browser produces the PDF.

## Money and arithmetic

- Money is an unsigned integer in **minor units** of the workspace currency (`tenants.currency`) next to a currency
  code; rates are **basis points** (10 000 = 100 %); time is whole minutes; leave is integer **units** (one day = 2).
  No float is used anywhere, in storage, calculation or CSV.
- Every ratio `value x numerator / denominator` is computed on big integers (no overflow of the product) and rounded
  **half-up once**, at the end (`PayMath::scale`). Sums fail rather than wrap.
- A reversal payslip carries the same positive amounts and `is_reversal = true`; reports subtract reversal rows.
- Currency: every salary revision stores the workspace currency at the time; a run refuses (as a blocking error) an
  employee whose revision is in another currency than the workspace's.

## Salary structures and revisions

- `payroll_structures` + `payroll_structure_components`: a component is an **earning** or **deduction**, calculated
  as a **fixed amount** or **a percentage of basic in basis points**, with a taxable flag, optional `effective_from` /
  `effective_to` and a sort order. A component applies to a period when it is active and overlaps it.
- The **basic salary is per employee**, on `payroll_salary_assignments`: an immutable revision (`revision`,
  `basic_amount`, `structure_id`, `effective_from`, `reason`, `actor_id`). The model throws on update and delete. A new
  revision must take effect **after** the latest one; a run uses the revision in force **on the last day of its
  period**. There is no salary data in HRM.
- `payroll_profiles` is Payroll's own per-employee record: the placement (`owner_id`, `department_id`, `branch_id`)
  copied from HRM so scoped permissions work on Payroll tables, and the payment account (method, holder, bank,
  branch, routing number, and the **encrypted** account number; only the last four characters are shown outside the
  payment sheet). The placement is refreshed from HRM events (`hrm.employee.created|updated|terminated`) by
  `RefreshEmployeePlacement`, which re-reads the employee through `EmployeeDirectory`; it also moves the account link
  (`owner_id`) of that person's existing payslips, never a money column.

## Pay periods

`payroll_periods`: `monthly` (a calendar month) or `custom` (any range up to 366 days), unique per range, never
overlapping. State machine:

```
open --prepare run--> processing --approve regular run--> approved --last payslip paid--> paid --close--> closed
  ^------ discard draft run ------+
```

Only an open period without runs can be edited or deleted. A period closes only when paid, with no draft/submitted
run and no pending item left. A closed period accepts nothing.

## Runs

`payroll_runs` have a kind: `regular` (one per period, enforced by `UNIQUE(tenant_id, period_id, regular_marker)`),
`adjustment` (pays items still pending for an approved/paid period) and `reversal` (undoes an approved run).

```
draft --submit--> submitted --approve (a different member)--> approved (locked)
  ^---- recalculate / send back ---+
```

### Calculating (`RunComputation`, `PayCalculator`)

A run can only be calculated **after the period's last day** and is **refused** while any employee with a salary has
`open_days > 0` in `EmployeeTimeSummary::attendance()`. Employees are listed through `EmployeeDirectory::list`
(cursor-paged) and kept when employed on any day of the period. An employee without a salary revision is skipped and
named in the run's `warnings` (`no_salary_assignment`).

| Figure | Rule |
|---|---|
| Payable days | Calendar days of the period between `joined_on` and the last working day (or termination date) |
| Basic | `basic x payable_days / period_days` |
| Fixed component | `amount x payable_days / period_days`; percentage components are taken from the **prorated** basic |
| Day divisor | The period's calendar days, or a fixed count (`fixed_days`, default 30), per setting `days_basis` |
| Day base | The full-period basic, or basic plus recurring earnings, per setting `daily_rate_base` (default `gross`) |
| Unpaid leave | `day base x unpaid units / (2 x divisor)` from `leaveUsage()` entries with `is_paid = false` |
| Absence | Same, for `days_absent x 2 + days_half x 1` units (setting `deduct_absence`) |
| Cap | Leave and absence deductions share one budget: never more than the prorated recurring earnings |
| Overtime | `basic x overtime_minutes x multiplier_bps / (divisor x work_day_minutes x 10 000)`; minutes optionally capped; multiplier default 15 000 (1.5x) |
| One-off items | Allowance, bonus, festival bonus (earnings), deduction, reimbursement; fixed `amount` **or** a share of the full monthly basic in bps |
| Gross | Basic + earning components + overtime + earning items. Reimbursements are added to the net, not to gross |
| Taxable | Basic + taxable components + taxable overtime + taxable items, minus unpaid-leave/absence deductions, floored at 0 |
| Rule sets | Evaluated on `basic`, `gross` or `taxable_gross`; employer-paid results are listed but not deducted |
| Loan installments | Due on or before the period end, earliest first; deducted only while they **fit** in what remains of net pay, otherwise deferred (warning `installment_deferred`) |
| Net | Gross + reimbursements - deductions; a negative net blocks submission (`negative_net`) |

Unpaid time that HRM has not closed (attendance days that were never generated) is not deducted; a period
with such gaps is not refused (only `open_days > 0` is).

### Approval, locking, numbering

- **Preparer.** `prepared_by_id` is the first member who calculated the run; a recalculation by someone else (or by
  no signed-in actor) does not replace it.
- **Amount range.** Rule-set amounts are bounded to 1 000 000 000 000 minor units on input; a calculation that still
  leaves the supported integer range becomes the run error `amount_out_of_range` for that employee (blocks
  submission) instead of a server error.
- **Four-eyes.** `approve` fails when the approver is the member who last calculated (`prepared_by_id`) or submitted
  (`submitted_by_id`) the run. The workspace may switch this off (`require_separate_approver`, sensitive, audited):
  default on. An automation-prepared run has no preparer, so any approver satisfies the rule.
- **Inputs must not drift.** The calculation stores a SHA-256 fingerprint of every figure it decided. Approval
  recalculates inside the transaction and refuses (asking for a recalculation) when anything differs: an item added,
  a salary revised, attendance corrected.
- **Locking.** Approval numbers the payslips and the run (gap-free `PSL-000001`, `RUN-000001`; prefixes and padding are
  settings, assigned only at approval, inside the transaction, from a row locked `FOR UPDATE`), sets `locked_at`,
  marks the consumed items `included` and the deducted installments `deducted` (a loan whose installments are all
  deducted becomes `completed`), and moves a regular run's period to `approved`.
- `Run` refuses update/delete once locked; `Payslip` refuses any change of a money or descriptive column and any
  delete once locked (only the payment columns and the account link may move); `PayslipLine` is never updated and can
  be deleted only with a draft payslip; `SalaryAssignment` and `Payment` are append-only. All of this is enforced by
  the models.
- **Reversal.** `reverse` creates a `reversal` run in `submitted` state holding a copy of the payslips with
  `is_reversal = true`; a **different** member approves it. Approval reopens the items and installments the reversed
  run had consumed (they can be paid again by an adjustment run), and a second reversal of the same run is refused
  (`UNIQUE(tenant_id, reverses_run_id)`). The original run is never edited. A regular run cannot be recalculated for a
  period once it is approved: corrections are adjustment runs. **Once a reversal is approved, the reversed run's
  payslips can no longer be paid or exported** (`PaymentManager::isPayable()`, enforced in `record`, run-level
  payment and the payment sheet; a reversal payslip is never payable) and the run page offers neither action.
- **Adjustment runs** apply only pending items of an approved/paid period (no basic, attendance, rule sets or loans)
  and go through the same submit/approve steps; their net must not be negative.

## Payments

`payroll_payments` is an append-only ledger of decisions on a payslip: `paid` or `failed`, with `method`
(`bank`, `cash`, `mobile_wallet`, `cheque`, `other`), `reference`, `paid_on` (not in the future), the amount
(always the full net; no partial payments) and a client-supplied **idempotency key**, unique per workspace. The
payslip carries the current status (`unpaid`, `paid`, `failed`). Replaying a key returns the first record and changes
nothing; the same key for another payslip is refused; `paid` is final; `failed` can later become `paid`. When the last
payslip of a period's regular run is paid the period becomes `paid`. A bulk call records one decision for every
unpaid payslip of an approved run using the keys `{key}:{payslip id}`.

## Rule sets (tax, contributions, levies)

`payroll_rule_sets` + ordered `payroll_rule_slabs`, entered by the workspace. A rule set has a `code`, category
(`income_tax`, `social_contribution`, `levy`, `other`), who pays (`employee` deduction or `employer` cost), the basis
it is computed on, effective dates, an optional employment-type filter, an optional minimum and maximum, and a
`version` that increases on every save (the evaluation detail is stored on the payslip line, so a later edit never
changes a locked payslip).

The generic `slab` engine (`SlabRuleSet`): amounts are minor units; bands are contiguous half-open `[from, to)`
ranges starting at 0, only the last open-ended; `rate_bps` 0-10 000 and an optional `fixed` amount.

- `progressive`: each band taxes the part of the base inside it at its own rate; a band's fixed amount is added once the
  base exceeds the band's start.
- `banded`: the one matching band's rate applies to the whole base, plus its fixed amount.
- `annualize` with `periods_per_year`: the base is multiplied up, evaluated, and divided back, **rounding once**.

Pluggability: `App\Modules\Payroll\Contracts\PayrollRuleSet` (`engine()`, `label()`, `validate()`, `evaluate()`) is the
contract of an evaluator; `RuleSetEngines` is a container singleton a provider registers further engines on, so
another country's scheme that is not a slab table can be added without touching the calculator. Engines must be pure.

## Payslip document

`GET /payroll/payslips/{payslip}/document` renders `resources/views/payroll/payslip.blade.php`: self-contained HTML
(inline CSS, the workspace logo embedded as a data URI), server-rendered in the viewer's locale (English or Bengali),
with a print button. Headers: `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`; `?download=1`
sends it as an attachment and writes an audit entry. It is the same record-scope check as the payslip itself: an
employee reaches exactly their own.

## Reports and CSV

`PayrollReports` (all over **approved** payslips, reversals counted negatively, sums in SQL): the register, cost by
department, and deductions (and employer contributions) by line code. `reports/export` returns each as CSV; the
**bank/payment sheet** (`runs/{run}/payment-sheet`) lists the unpaid, non-reversal payslips of an approved run with the
employee's payment account. CSV is written by `SafeCsv`: UTF-8 with a byte-order mark, RFC 4180 quoting, and **formula
injection neutralized** (a text cell whose first visible character is `=`, `+`, `-`, `@`, tab or carriage return is
prefixed with `'`); amounts are exact decimal strings produced from integers. Exports are audited.

## Events and automation

Events (after commit; ids and scalars): `payroll.run.approved`, `payroll.payslip.paid`, `payroll.run.reversed`
(payloads in `app/Modules/Payroll/Automation/Triggers`; all three triggers require `payroll.runs.view`).

Two actions with durable receipts (`payroll_automation_action_receipts`, `UNIQUE(tenant_id, action,
idempotency_key)`), both of which only produce something a person must still approve:

- `payroll.item.create`: a **pending** one-off item. It is paid only if a run that includes it is approved.
- `payroll.run.prepare_draft`: calculates the regular run of a period as a **draft**. It cannot submit or approve.

Approving, submitting, reversing and recording payments are deliberately **not** actions.

## Permissions

| Permission | Scopable | Notes |
|---|---|---|
| `payroll.structures.view` / `.manage` | no | structures and components; manage is sensitive |
| `payroll.salaries.view` | yes, sensitive | salary revisions and masked account |
| `payroll.salaries.manage` | yes, sensitive | new revisions, payment account |
| `payroll.periods.view` / `.manage` | no | pay periods |
| `payroll.runs.view` | no, sensitive | runs, totals, draft payslips, run triggers |
| `payroll.runs.prepare` | no, sensitive | calculate, recalculate, submit, discard, adjustment runs |
| `payroll.runs.approve` | no, sensitive | approve or send back (never one's own work) |
| `payroll.runs.reverse` | no, sensitive | create a reversal run (approved by someone else) |
| `payroll.items.view` / `.manage` | yes, sensitive | one-off items |
| `payroll.loans.view` / `.manage` | yes, sensitive | loans and advances |
| `payroll.loans.approve` | no, sensitive | approve or reject (not one's own request) |
| `payroll.rules.view` / `.manage` | no | rule sets; manage is sensitive |
| `payroll.payslips.view` | yes, sensitive | **self-service** is the `own` scope |
| `payroll.payments.record` | no, sensitive | record paid/failed |
| `payroll.payments.export` | no, sensitive | the bank/payment sheet (decrypted account numbers) |
| `payroll.reports.view` | no, sensitive | register, cost and deduction reports and their CSV |
| `payroll.settings.manage` | no, sensitive | day valuation, overtime, approval policy, numbering |

Scoped records carry `owner_id` (the employee's membership) and copied `department_id` / `branch_id`, exactly like
HRM records. Approved payslips of someone else are a 404 to an `own`-scoped employee, in every route. A draft payslip
is visible only to people who hold `payroll.runs.view`.

## Limits

`payroll.payslips` (metered): payslips issued per billing period, counted when a regular or adjustment run is
approved (`UsageMeter::record`, key `payroll.run:{run id}`); checked at submission and again at approval.

## Audit

Config models use `Auditable` (settings, structures, components, profiles without the account fields, periods, items,
loans, rule sets). Business events are written inside the causing transaction: `payroll.salary.revised`,
`payroll.account.updated`, `payroll.period.closed`, `payroll.run.prepared|submitted|returned|discarded|approved|
reversal_requested|reversed`, `payroll.loan.approved|rejected|cancelled`, `payroll.payment.recorded`,
`payroll.payslip.downloaded`, `payroll.report.exported`, `payroll.payment_sheet.exported`, `payroll.settings.saved`,
`payroll.rule_set.saved`. `payroll.salary.revised` carries ids, the revision number and the effective date only,
never the salary amounts (they live in the append-only `SalaryAssignment` ledger).

## Routes

All under `{tenant}.APP_DOMAIN/payroll`, behind `auth:web`, `auth.session`, `member`, `subscribed`, `entitled:payroll`
and a `can:` permission (the record scope is applied again by the controller; out of scope answers 404).

| Name | Verb and path | Permission |
|---|---|---|
| `payroll.payslips.index` / `.show` / `.document` | GET `/payslips`, `/payslips/{payslip}`, `/payslips/{payslip}/document` | payslips.view |
| `payroll.payments.store` | POST `/payslips/{payslip}/payment` | payments.record |
| `payroll.periods.index` / `.show` | GET `/periods`, `/periods/{period}` | periods.view |
| `payroll.periods.store` / `.update` / `.destroy` / `.close` | POST `/periods`, PUT / DELETE `/periods/{period}`, POST `/periods/{period}/close` | periods.manage |
| `payroll.runs.index` / `.show` | GET `/runs`, `/runs/{run}` | runs.view |
| `payroll.runs.store` | POST `/periods/{period}/runs` | runs.prepare |
| `payroll.runs.adjustments.store` | POST `/periods/{period}/adjustment-runs` | runs.prepare |
| `payroll.runs.recalculate` / `.submit` / `.destroy` | POST `/runs/{run}/recalculate`, `/runs/{run}/submit`, DELETE `/runs/{run}` | runs.prepare |
| `payroll.runs.approve` / `.return` | POST `/runs/{run}/approve`, `/runs/{run}/return` | runs.approve |
| `payroll.runs.reverse` | POST `/runs/{run}/reverse` | runs.reverse |
| `payroll.runs.payments.store` | POST `/runs/{run}/payments` | payments.record |
| `payroll.runs.payment-sheet` | GET `/runs/{run}/payment-sheet` | payments.export |
| `payroll.structures.index` / `.show` | GET `/structures`, `/structures/{structure}` | structures.view |
| `payroll.structures.store` / `.update` | POST `/structures`, PUT `/structures/{structure}` | structures.manage |
| `payroll.structures.components.store` / `.update` / `.destroy` | POST `/structures/{structure}/components`, PUT / DELETE `.../{component}` | structures.manage |
| `payroll.salaries.index` / `.show` | GET `/salaries`, `/salaries/{profile}` | salaries.view |
| `payroll.salaries.store` | POST `/salaries` (first salary of an employee) | salaries.manage |
| `payroll.salaries.revisions.store` | POST `/salaries/{profile}/revisions` | salaries.manage |
| `payroll.salaries.account.update` | PUT `/salaries/{profile}/account` | salaries.manage |
| `payroll.items.index` | GET `/items` | items.view |
| `payroll.items.store` / `.update` / `.destroy` | POST `/items`, PUT / DELETE `/items/{item}` (destroy cancels) | items.manage |
| `payroll.loans.index` / `.show` | GET `/loans`, `/loans/{loan}` | loans.view |
| `payroll.loans.store` / `.cancel` | POST `/loans`, POST `/loans/{loan}/cancel` | loans.manage |
| `payroll.loans.decision` | POST `/loans/{loan}/decision` | loans.approve |
| `payroll.rules.index` / `.show` | GET `/rules`, `/rules/{ruleSet}` | rules.view |
| `payroll.rules.store` / `.update` | POST `/rules`, PUT `/rules/{ruleSet}` | rules.manage |
| `payroll.reports.index` / `.export` | GET `/reports`, `/reports/export` | reports.view |
| `payroll.settings.index` / `.update` | GET, PUT `/settings` | settings.manage |

## Pages the controllers render

Component names are `Modules/Payroll/Pages/<Name>`. Props are camelCase; money is integer minor units; dates are
`Y-m-d`, instants ISO 8601 UTC. Shared shapes: `employee {id, number, name, department, designation, status}|null`,
`period {id, name, kind, startsOn, endsOn, payDate, status, closedAt}`, `run {id, number, kind, status, periodId,
periodName, reversesRunId, currency, employeeCount, grossAmount, deductionAmount, reimbursementAmount, netAmount,
employerAmount, preparedById, submittedById, approvedById, submittedAt, approvedAt, isLocked, note, createdAt}`,
`payslipRow {id, number, runId, periodId, periodName, employeeId, employeeNumber, employeeName, departmentName,
designationName, currency, basicAmount, grossAmount, deductionAmount, reimbursementAmount, netAmount, employerAmount,
isReversal, isLocked, paymentStatus, paymentMethod, paymentReference, paidOn, warnings[]}`.

| Page | Props |
|---|---|
| `Payslips/Index` | `payslips` (paginator of `payslipRow`), `filters`, `periods[]`, `paymentStatuses[]` |
| `Payslips/Show` | `payslip` (row + `runNumber, runKind, reversesPayslipId, payableDays, periodDays, taxableAmount, structureName, attendance, joinedOn, employmentType`), `period`, `lines[{id, kind, code, name, source, amount, isTaxable, detail}]`, `payments[]`, `paymentMethods[]`, `abilities {recordPayment}` |
| `Runs/Index` | `runs` (paginator), `filters`, `statuses[]`, `kinds[]`, `preparablePeriods[]`, `canPrepare` |
| `Runs/Show` | `run` (+ `preparedByName, approvedByName, errors[], warnings[], reversesRunNumber, isReversed`), `period`, `payslips` (paginator), `filters`, `paymentStatuses[]`, `approval {requiresSeparateApprover, youPreparedOrSubmitted}`, `abilities {recalculate, submit, sendBack, approve, discard, reverse, recordPayments, exportPaymentSheet}` |
| `Periods/Index` | `periods` (paginator), `kinds[]`, `today`, `canManage` |
| `Periods/Show` | `period`, `runs[]`, `pendingItems`, `hasEnded`, `abilities {manage, prepare, viewRuns}` |
| `Salaries/Index` | `profiles` (paginator of `{id, employee, current, upcoming, hasAccount}`), `filters`, `departments[]`, `unassigned[]`, `structures[]`, `currency`, `canManage` |
| `Salaries/Show` | `profile {id, employeeId}`, `employee`, `assignments[{id, revision, structureId, structureName, basicAmount, currency, effectiveFrom, reason, createdAt}]`, `account {paymentMethod, accountHolder, bankName, bankBranch, routingNumber, accountNumberMasked, hasAccountNumber}`, `structures[]`, `paymentMethods[]`, `currency`, `today`, `canManage` |
| `Structures/Index` | `structures[]`, `canManage` |
| `Structures/Show` | `structure`, `components[]`, `options {kinds, calcs}`, `currency`, `canManage` |
| `Items/Index` | `items` (paginator, rows add `employee`), `filters`, `periods[]`, `employees[]`, `types[]`, `statuses[]`, `currency`, `canManage` |
| `Loans/Index` | `loans` (paginator, rows add `employee`), `filters`, `statuses[]`, `kinds[]`, `employees[]`, `currency`, `today`, `canManage` |
| `Loans/Show` | `loan`, `employee`, `installments[]`, `outstandingAmount`, `abilities {approve, cancel}` |
| `Rules/Index` | `ruleSets[]`, `options`, `currency`, `canManage` |
| `Rules/Show` | `ruleSet`, `options`, `currency`, `canManage` |
| `Reports/Index` | `filters`, `periods[]`, `departments[]`, `currency`, `totals`, `register` (paginator), `departmentCost[]`, `deductions[]`, `employerContributions[]` |
| `Settings/Index` | `settings`, `currency`, `options {daysBasis, rateBases, engines}` |

Dashboard widgets: `payroll.self` (`PayrollSelfWidget`, `payroll.payslips.view`: `count`, `latest`) and
`payroll.overview` (`PayrollOverviewWidget`, `payroll.runs.view`: `currency, openPeriods, awaitingApproval,
unpaidPayslips, pendingLoans, latestRun`).

## Deployment

Run migrations (`app/Modules/Payroll/Database/Migrations`), then `php artisan permissions:sync` and
`php artisan apps:sync --prune`. New permissions are granted to no existing role; owners hold them implicitly. Enter
salary structures, salaries, payment accounts and (if wanted) rule sets in the pages: nothing is created for a
workspace automatically.

## Not built

Payment gateway or bank-file integrations, partial payments, interest on loans, tax certificates and
year-end returns, per-employee component overrides, working-day divisors, mid-period salary proration, leave
encashment, provident-fund ledgers, multi-currency payroll, preloaded country rule sets, scheduled (automatic) runs,
notification delivery (Phase 8).
