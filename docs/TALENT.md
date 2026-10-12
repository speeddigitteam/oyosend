# Talent & Performance

The fifth production optional app is `talent` (name "Talent & Performance", category HR & Payroll), implemented under
`app/Modules/Talent` and registered by `TalentServiceProvider`. It is the "Additional HR" half of the HR suite:
**recruitment, onboarding and offboarding, probation tracking, performance reviews and goals, training, and HR
analytics**. Employees, attendance and leave stay in `hrm` ([HRM.md](HRM.md)).

It depends on `core` and `hrm` and reads employees only through the `App\Core\Workforce` contracts
(`EmployeeDirectory`, `EmployeeSnapshot`) and HRM's domain events. **No HRM model is imported and no HRM table is
written.** Candidates are Talent's own records: never a `User`, never a row in a shared contacts table.

The code ships with Oyosend; a workspace needs an effective plan or App Store entitlement for `talent` (which
itself needs `hrm`) before its routes, navigation, permissions, automation definitions, widgets or scheduled jobs
are usable. Without it every route answers 404. Removing the entitlement hides the app and retains every record.
The offer is free (`included`); the demo Business and Enterprise plans include it.

State of the build: **backend and Vue pages complete (Phase 7, part C)**; the page names and prop shapes below are
what the controllers render and `resources/js/Modules/Talent` consumes. Tables are prefixed `talent_`.

Frontend notes: pages live in `resources/js/Modules/Talent/Pages`, shared pieces (dialogs, the application board,
formatting helpers, types) in `Components/`, strings in `locales/{en,bn}/talent.ts`. The applications page is a
Kanban board by pipeline stage once a job opening is filtered (drag and drop, plus a "Move to stage" list on each
card for keyboards and touch) with a list view as the alternative; only open applications move, and only between
active stages. Review-form weights are typed as percent and sent as basis points, with a running total that must
reach exactly 100%. Scores are held in hundredths and shown with two decimals in the person's numerals. Money is
typed as an exact decimal and sent as integer minor units (`Core/lib/minorUnits.ts`). Review forms are edited on
the review cycles page; the settings page links there.

## Identity and scoping

- An **employee** is referenced by a plain `employee_id` (no foreign key to HRM). Names, status and placement are
  read through `EmployeeDirectory` every time; Talent never copies a snapshot as a source of truth. The one
  exception is the **placement copy** below.
- Records about one employee (probation, checklists, reviews, goals, enrolments) carry `owner_id` (the employee's
  workspace membership, null while they have no account), `department_id` and `branch_id` copied from the
  employee snapshot when the record is created (`Models\Concerns\Placed`). Scoped permissions (`own`,
  `department`, `branch`) compare those columns through `RecordAccess`, exactly as in HRM. The copy is kept in
  step by the `hrm.employee.updated` listener: the **owner** follows everywhere (so linking an account gives the
  person access to their history); department and branch change only on records still in progress.
- Recruitment records are owned differently. A **job opening** is owned by the hiring manager's membership (or the
  creator when the hiring manager has no account), with the department/branch chosen on the opening. An
  **application** and an **interview** copy the opening's placement. A **candidate** is owned by the recruiter who
  created it (`HasRecordOwner`), so a hiring manager with `own` scope sees their openings and applications but not
  candidates they did not create.
- **Self-service is the `own` scope.** A role granted `talent.reviews.view`, `talent.goals.view/manage`,
  `talent.enrolments.view/manage`, `talent.onboarding.view` with scope `own` sees and acts on exactly that
  person's records. The reporting manager reaches reviews through `reviewer_employee_id` and the assignee reaches
  their tasks through `assignee_employee_id`, not through scope.
- Whoever acts for themselves needs an employee record linked to their account (`EmployeeDirectory::findByMembership`).
  Without one every self-service question answers no.

### Security notes

- **Employee pickers follow the record scope.** The employee lists of the goal, enrolment, checklist and
  interview forms (`TalentSupport::employeeOptions()`) hold only the employees inside the scope the actor's
  `*.manage` permission was granted with (`own` is just their own record), and the endpoints that take a chosen
  employee (goal, enrolment, checklist start, task assignee, interview panel) refuse an id outside that scope.
- **Candidate details follow the candidate record scope.** On the applications list and detail and on an opening's
  application list, headline, email, phone, notes and documents are sent only when the actor's
  `talent.candidates.view` scope covers that candidate (not merely the permission), and the applications search
  matches only candidates inside that scope.
- **No self-service decisions.** Completing, failing or cancelling one's own enrolment and deciding one's own
  probation need `all` scope (same rule as HRM's own leave adjustments and attendance management).
- **Candidate name stays, headline and contact details do not.** The name is shown to everyone who legitimately
  works an application or interview (panelists, hiring manager, `applications.view` holders), because the
  workflow needs it; headline, email, phone, summary and documents are only sent with `talent.candidates.view`.

## Recruitment

| Concept | Rules |
|---|---|
| Pipeline | `talent_pipelines` with ordered `talent_pipeline_stages` (`position`, `type` = `active`, `hired`, `rejected`). A pipeline has at most one hired and one rejected stage; the type of a stage that holds applications cannot change. The standard pipeline (Applied, Screening, Interview, Offer, Hired, Rejected) is created on first use. One pipeline is the default |
| Job opening | Status `draft`, `open`, `on_hold`, `closed` (draft to open or closed; open to on_hold or closed; on_hold to open or closed; closed to open). Opening counts against the plan limit `talent.job_openings` under a tenant row lock. `headcount` cannot drop below the hires; the pipeline cannot change once there are applications |
| Candidate | `talent_candidates`: contact fields, expected compensation (integer minor units + currency), `consent_basis`, `consent_given_at`, `retain_until` (default: today + `candidate_retention_months`), `anonymized_at`. Counted against `talent.candidates` (not anonymized) |
| Duplicates | Email is normalised (trimmed, lower case). Creating or editing onto an existing, non-anonymized email is refused with an error on `email` unless `confirm_duplicate` is sent; `GET candidates/lookup?email=` lists the matches the person may see. No automatic merge |
| Application | One per (opening, candidate). Starts at the pipeline's first `active` stage. Only on an `open` opening |
| Move history | `talent_application_history` is **append-only** (the model throws on update/delete): `applied`, `moved`, `rejected`, `hired`, `withdrawn`, `reopened` with from/to stage, reason, note, actor |
| Rejection | Optional active reason + note; moves to the pipeline's rejected stage; a live offer is withdrawn. A rejected or withdrawn application can be reopened (not onto a closed opening, not for an anonymized candidate) |
| Notes | `talent_notes` on a candidate, optionally tied to one application |
| Documents | `talent_candidate_documents`, private files only through `TenantStorage` under `talent/candidates/{candidate}/documents/`; 10 MB; pdf, doc(x), png, jpg, webp, txt; download needs `talent.candidates.view` over the candidate, is sent as an attachment with `nosniff` and `Cache-Control: private, no-store` |
| Interviews | Entered as a **local date and time in the workspace timezone**, stored as UTC `starts_at`/`ends_at` plus the zone name. 15 min to 8 h, not in the past, panel of 1 to 10 working employees, an interviewer cannot have two overlapping scheduled interviews. Status `scheduled`, `completed`, `cancelled`, `no_show`; outcome `advance`, `hold`, `reject` |
| Scorecards | One per panelist and interview (rating 1 to 5, recommendation `strong_no`/`no`/`yes`/`strong_yes`, strengths, concerns), only after the interview started, editable until it is completed. **Blind feedback**: a panelist without management rights sees only their own until they have submitted, then everyone's; `interviews.manage` or `applications.hire` over the record sees all; anyone else none |
| Offers | `talent_offers`: tracking only (amount in minor units, currency, proposed start, expiry, status `draft`, `sent`, `accepted`, `declined`, `withdrawn`). At most one live offer per application (unique marker). **No contract is generated or signed.** The latest offer of the application, live or not, must be `accepted` before the hire: a declined or withdrawn offer blocks it until a new offer is accepted |
| Hire | `ApplicationManager::hire` moves the application to the hired stage, stamps `hired_at`/`start_on`, refuses when every position is filled, and closes the opening automatically when the last one is. It announces `talent.candidate.hired` |

### The hire hand-off to HRM (a documented gap)

`talent.candidate.hired` carries `application_id, opening_id, candidate_id, first_name, last_name, email, phone,
job_title, employment_type, department_id, branch_id, hiring_manager_id, start_on, offer_amount, offer_currency`
(ids and scalars). **HRM exposes no hire-intake contract today**, so nothing creates the employee from it: HR creates
the employee in HRM (`hrm.employees.create`) using this data. When HRM publishes a contract (an
`EmployeeIntake`-style interface in `App\Core\Workforce`) a Talent listener can call it from this event; until then
the integration point is the event and any automation workflow subscribed to its trigger. Talent never writes
`hrm_*` tables. Once HRM creates the employee, `hrm.employee.created` starts the onboarding checklist as usual.

## Onboarding, offboarding and probation

- `talent_checklist_templates` (kind `onboarding` | `offboarding`, one default per kind) hold ordered
  `talent_template_tasks`: title, assignee role (`employee`, `manager`, `hr`, `person`) and due offset in days
  from the anchor date (negative = before). Templates are workspace configuration (`talent.settings.manage`).
- A **checklist** (`talent_checklists`) is a copy of a template for one employee, anchored on the joining date
  (onboarding) or the last working day (offboarding). Task assignees are resolved when it starts: `employee` is
  the employee, `manager` their reporting manager at that moment, `person` a fixed employee, `hr` nobody (anyone
  holding `talent.onboarding.manage` over the record completes it). Tasks are `pending`, `done`, `skipped` (a task a manager skipped is changed only by someone managing the
  checklist, never by its assignee); the
  checklist completes when none is pending (announcing `talent.checklist.completed` once) and reopens when a task is
  added or reopened.
- **Driven by HRM events, once per event.** `hrm.employee.created` starts the default onboarding checklist
  (`source_key = hrm.employee.created:{employee_id}`) and begins probation tracking when the status is
  `probation`; `hrm.employee.terminated` starts the default offboarding checklist
  (`source_key = hrm.employee.terminated:{employee_id}:{terminated_on}`), closes the open probation as `ended` and
  cancels open enrolments. `source_key` is unique per workspace, so a redelivered or duplicated event creates
  nothing. A workspace not entitled to Talent, or without an active template of that kind, is skipped silently. A
  failure in this listener is reported and never breaks the HRM action.
- **Probation** (`talent_probations`, one open per employee): `started_on` is the joining date, `ends_on` is
  HRM's `probation_ends_on` (carried by `EmployeeSnapshot::probationEndsOn`) or, when HRM has none, the joining
  date plus `default_probation_days`. `observed_ends_on` remembers the date last read from HRM: **a change in HRM
  replaces `ends_on`, an extension decided in Talent does not.** HRM moving the employee out of `probation`
  closes it as `confirmed`; a terminated employee closes it as `ended`. A decision (`confirm`, `extend` with a
  later date, `end`) is recorded in `talent_probation_reviews` and announced with
  `talent.probation.decision_recorded`; **HRM changes the employee status itself** (a recommendation, not an
  action on `hrm_employees`).
- **Reminders** (scheduled job `talent.reminders`, daily): a probation with no final decision whose end date is
  within `probation_reminder_days` announces `talent.probation.review_due` once per (probation, end date).

## Performance

| Concept | Rules |
|---|---|
| Review form | `talent_review_forms` with weighted competencies (`talent_review_form_items.weight_bp`). Scale 3 to 10. **The weights of a form add up to exactly 10 000 basis points** (validated on save). The items and scale of a form used by a launched cycle are locked |
| Cycle | `draft` to `active` (launch) to `calibration` to `closed`. Launch creates one review per employee in scope (working statuses, joined by the cycle start, optional department), idempotently |
| Review | `pending_self` to `pending_manager` to `pending_calibration` to `calibrated` (locked) to `acknowledged`. A cycle without a self step starts at `pending_manager` |
| Steps | Self: the employee. Manager: `reviewer_employee_id` (defaults to the reporting manager at launch; reassignable with `talent.cycles.manage`), never the subject. Steps only while the cycle is `active`; every competency must be rated 1..scale |
| Scoring | `ReviewScoring::weighted`: integer `sum(rating * weight_bp)` divided by 100, rounded half up, held in **hundredths** (4.35 is `435`). No float, nothing invented |
| Calibration | While the cycle is in `calibration`, `talent.reviews.calibrate` over the record sets the final score: the manager's score, or an explicit override within the scale that needs a note. Nobody calibrates their own review. The review is then locked |
| Release | An employee never sees the manager's ratings, comments, score or the final score before calibration; self data is always visible to them |
| Acknowledgement | The employee acknowledges a calibrated review once, with an optional comment |
| Goals / KPIs | `talent_goals`: integer `start_value`, `target_value`, `current_value`, optional unit, due date, cycle. Progress is `GoalProgress::basisPoints` (clamped, works for targets below the start). `talent_goal_updates` is append-only. Reaching the target achieves the goal (`talent.goal.achieved`) |

## Training

- `talent_courses` (optional `validity_months` makes a course a certification, `is_mandatory`), `talent_training_sessions`
  (local date/time stored as UTC + zone, capacity), `talent_enrolments` (`enrolled`, `completed`, `failed`,
  `cancelled`; at most one open enrolment per employee and course through a unique marker).
- Completing sets `completed_on` (not in the future), an optional 0-100 score and `expires_on = completed_on +
  validity_months` (no overflow: 31 Jan + 1 month is 28 Feb).
- **Expiry reminders** (job `talent.reminders`): a completed, unexpired certification within
  `certification_reminder_days` announces `talent.certificate.expiring` once per (enrolment, expiry date); one
  past its date announces `talent.certificate.expired` once. A certificate superseded by a later completion of the
  same course by the same person, and a terminated employee's, are skipped. The `talent_reminders` row (unique key)
  is written in the same transaction as the event, so reruns and overlapping workers announce each fact once.

## HR analytics

`TalentAnalytics` (`talent.analytics.view`, workspace-wide, sensitive): the hiring funnel (applications that ever
reached each stage, outcomes, hire rate in bp), openings by status, **time to fill** (opened to last hire, whole
days half-up) and **time to hire** (applied to hired); **headcount and attrition** per month from
`EmployeeDirectory` (joiners, leavers, headcount at month end, attrition in bp = leavers over the average
headcount); review completion per cycle; training completion rate and certificates expiring or expired. The
dashboard widgets are `talent.self` (own tasks, reviews, goals, training) and `talent.overview` (counts).

## Domain events and automation

Events (after commit; ids and scalars, never review text or notes): `talent.opening.published|closed`,
`talent.application.created|stage_changed|rejected`, `talent.candidate.hired`, `talent.candidate.anonymized`,
`talent.interview.scheduled|completed`, `talent.offer.accepted`, `talent.checklist.started|completed`,
`talent.probation.review_due|decision_recorded`, `talent.review_cycle.launched`,
`talent.review.submitted|calibrated|acknowledged`, `talent.goal.achieved`, `talent.training.enrolled|completed`,
`talent.certificate.expiring|expired`.

Triggers (9): `talent.candidate.hired`, `talent.application.stage_changed`, `talent.interview.scheduled`,
`talent.checklist.completed`, `talent.probation.review_due`, `talent.review.calibrated`, `talent.training.completed`,
`talent.certificate.expiring`, `talent.certificate.expired`.

Actions with durable receipts (`talent_automation_action_receipts`, `UNIQUE(tenant_id, action, idempotency_key)`):
`talent.checklist.start` (employee, kind, optional template; the checklist source key is derived from the
idempotency key) and `talent.course.enrol`. Hiring, rejecting, calibrating and approving are deliberately **not**
actions.

## Permissions

| Permission | Scopable | Notes |
|---|---|---|
| `talent.self.view` | no | the `me` page and the self widget |
| `talent.openings.view` / `.create` / `.manage` | view, manage | create is workspace-wide |
| `talent.candidates.view` / `.manage` | yes, sensitive | candidate data, notes, documents, anonymization |
| `talent.applications.view` | yes, sensitive | list, show, history |
| `talent.applications.manage` | yes | move, reject, withdraw, reopen, notes, add a candidate to an opening |
| `talent.applications.hire` | yes, sensitive | the hire |
| `talent.interviews.view` / `.manage` | yes | list/show; schedule, change, cancel, complete |
| `talent.interviews.feedback` | no, sensitive | submit a scorecard (the controller requires being on the panel) |
| `talent.offers.manage` | yes, sensitive | offer amounts are only sent to holders |
| `talent.onboarding.view` / `.manage` | yes | checklists; start, add tasks, skip, cancel |
| `talent.tasks.complete` | no | the "my tasks" list and completing a task you are assigned (or manage in scope) |
| `talent.probation.view` / `.manage` | yes / yes, sensitive | probation list; record a decision |
| `talent.cycles.manage` | no, sensitive | review forms and cycles, assign reviewers |
| `talent.reviews.view` | yes, sensitive | reviews in scope (plus the ones you review); acknowledge your own |
| `talent.reviews.submit` | no | take the self or manager step (the controller checks who you are for the review) |
| `talent.reviews.calibrate` | yes, sensitive | calibrate while the cycle is in calibration |
| `talent.goals.view` / `.manage` | yes | own goals through `own` |
| `talent.courses.view` / `.manage` | no | the catalogue and sessions |
| `talent.enrolments.view` / `.manage` | yes | enrol (yourself through `own`), complete, fail, cancel |
| `talent.analytics.view` | no, sensitive | analytics page and the overview widget |
| `talent.settings.manage` | no, sensitive | retention/reminder windows, pipelines, reasons, checklist templates |

Role design notes: an HR role needs `talent.onboarding.manage` **and** `talent.tasks.complete` to complete tasks
from the checklist page; an interviewer role is `talent.interviews.view` (`own`) + `talent.interviews.feedback`;
an employee role is `talent.self.view`, `talent.reviews.view` (`own`), `talent.reviews.submit`, `talent.goals.view`
and `.manage` (`own`), `talent.enrolments.view` and `.manage` (`own`), `talent.tasks.complete`.

## Limits

`talent.job_openings` (gauge): openings with status `open`; checked under a tenant row lock when an opening is
opened. `talent.candidates` (gauge): candidates that are not anonymized; checked under the same lock at creation.

## Scheduled jobs

Both daily, queue `automation`, through the entitlement-gated runner (`apps:run-scheduled`):

| Key | Job | What it does |
|---|---|---|
| `talent.reminders` | `SendReminders` | probation mirror sync and review-due reminders; certificate expiring and expired announcements |
| `talent.retention` | `EnforceRetention` | anonymizes candidates whose `retain_until` has passed and who have no open application |

Anonymization deletes the candidate's documents (files included) and notes, clears every personal field and the
free text written about the candidate (interview scorecard strengths/concerns, interview notes, application
rejection notes, offer notes), withdraws open applications and keeps the application rows as statistics.

## Audit

Models use `Auditable` (candidate contact fields, offer amounts, comments and notes are excluded). Business events
written inside the causing transaction: `talent.opening.status_changed`, `talent.application.moved|rejected|
withdrawn|reopened|hired`, `talent.candidate.anonymized`, `talent.candidate_document.uploaded|removed`,
`talent.interview.scheduled|rescheduled|completed|cancelled|no_show`, `talent.scorecard.submitted`,
`talent.offer.status_changed`, `talent.checklist.started|completed|cancelled`, `talent.probation.*`,
`talent.review_cycle.*`, `talent.review.*`, `talent.goal.*`, `talent.enrolment.completed`, `talent.settings.saved`.

## Routes

All under `{tenant}.APP_DOMAIN/talent`, behind `auth:web`, `auth.session`, `member`, `subscribed`, `entitled:talent`
and a `can:` permission (the record scope is applied again by the controller; an out-of-scope record answers 404).

| Name | Verb and path | Permission |
|---|---|---|
| `talent.me` | GET `/me` | `self.view` |
| `talent.openings.index` / `.create` / `.store` | GET `/openings`, GET `/openings/create`, POST `/openings` | view / create / create |
| `talent.openings.show` / `.update` / `.status` | GET, PUT `/openings/{opening}`, POST `/openings/{opening}/status` | view / manage / manage |
| `talent.openings.applications.store` | POST `/openings/{opening}/applications` | `applications.manage` |
| `talent.candidates.index` / `.lookup` / `.store` | GET `/candidates`, GET `/candidates/lookup`, POST `/candidates` | view / view / manage |
| `talent.candidates.show` / `.update` / `.anonymize` | GET, PUT `/candidates/{candidate}`, POST `.../anonymize` | view / manage / manage |
| `talent.candidates.notes.store` | POST `/candidates/{candidate}/notes` | manage |
| `talent.candidates.documents.store` / `.download` / `.destroy` | POST `/candidates/{candidate}/documents`, GET/DELETE `.../documents/{document}` | manage / view / manage |
| `talent.applications.index` / `.show` | GET `/applications`, GET `/applications/{application}` | `applications.view` |
| `talent.applications.move` / `.reject` / `.withdraw` / `.reopen` / `.notes.store` | POST `/applications/{application}/move`, `/reject`, `/withdraw`, `/reopen`, `/notes` | `applications.manage` |
| `talent.applications.hire` | POST `/applications/{application}/hire` | `applications.hire` |
| `talent.applications.offers.store` / `talent.offers.update` / `.status` | POST `/applications/{application}/offers`, PUT `/offers/{offer}`, POST `/offers/{offer}/status` | `offers.manage` |
| `talent.interviews.index` / `.show` | GET `/interviews`, GET `/interviews/{interview}` | `interviews.view` (+ panel membership) |
| `talent.interviews.store` | POST `/applications/{application}/interviews` | `interviews.manage` |
| `talent.interviews.update` / `.cancel` / `.complete` / `.no-show` | PUT `/interviews/{interview}`, POST `.../cancel`, `.../complete`, `.../no-show` | `interviews.manage` |
| `talent.interviews.scorecard` | POST `/interviews/{interview}/scorecard` | `interviews.feedback` (+ panel) |
| `talent.checklists.index` / `.show` | GET `/checklists`, GET `/checklists/{checklist}` | `onboarding.view` |
| `talent.checklists.store` / `.cancel` / `.tasks.store` | POST `/checklists`, POST `.../cancel`, POST `.../tasks` | `onboarding.manage` |
| `talent.tasks.index` | GET `/tasks` | `tasks.complete` |
| `talent.tasks.complete` / `.reopen` | POST `/checklist-tasks/{task}/complete`, `/reopen` | `tasks.complete` (+ assignee or manage) |
| `talent.tasks.skip` | POST `/checklist-tasks/{task}/skip` | `onboarding.manage` |
| `talent.probations.index` / `.show` | GET `/probations`, GET `/probations/{probation}` | `probation.view` |
| `talent.probations.decisions.store` | POST `/probations/{probation}/decisions` | `probation.manage` |
| `talent.cycles.index` / `.store` / `.show` / `.update` | GET, POST `/cycles`, GET, PUT `/cycles/{cycle}` | `cycles.manage` |
| `talent.cycles.launch` / `.calibration` / `.close` | POST `/cycles/{cycle}/launch`, `/calibration`, `/close` | `cycles.manage` |
| `talent.forms.store` / `.update` | POST `/review-forms`, PUT `/review-forms/{form}` | `cycles.manage` |
| `talent.reviews.reviewer` | POST `/reviews/{review}/reviewer` | `cycles.manage` |
| `talent.reviews.index` / `.show` | GET `/reviews`, GET `/reviews/{review}` | `reviews.view` |
| `talent.reviews.self` / `.manager` | POST `/reviews/{review}/self`, `/manager` | `reviews.submit` |
| `talent.reviews.calibrate` | POST `/reviews/{review}/calibrate` | `reviews.calibrate` |
| `talent.reviews.acknowledge` | POST `/reviews/{review}/acknowledge` | `reviews.view` (+ be the employee) |
| `talent.goals.index` / `.show` | GET `/goals`, GET `/goals/{goal}` | `goals.view` |
| `talent.goals.store` / `.update` / `.progress` / `.status` | POST `/goals`, PUT `/goals/{goal}`, POST `.../progress`, `.../status` | `goals.manage` |
| `talent.courses.index` / `.show` | GET `/courses`, GET `/courses/{course}` | `courses.view` |
| `talent.courses.store` / `.update` / `.sessions.store` / `talent.sessions.update` | POST `/courses`, PUT `/courses/{course}`, POST `.../sessions`, PUT `/training-sessions/{session}` | `courses.manage` |
| `talent.enrolments.index` | GET `/enrolments` | `enrolments.view` |
| `talent.enrolments.store` / `.complete` / `.fail` / `.cancel` | POST `/enrolments`, POST `/enrolments/{enrolment}/complete`, `/fail`, `/cancel` | `enrolments.manage` |
| `talent.analytics.index` | GET `/analytics` | `analytics.view` |
| `talent.settings.index` / `.update` | GET, PUT `/settings` | `settings.manage` |
| `talent.settings.pipelines.*`, `.stages.*`, `.rejection-reasons.*`, `.templates.*` | POST/PUT under `/settings/...` | `settings.manage` |

## Pages the controllers render

Component names are `Modules/Talent/Pages/<Name>`. Props are camelCase; dates are `Y-m-d`, instants ISO 8601 UTC;
money is integer minor units next to a `currency`; scores are integer hundredths (`scoreText` has the display
form); durations in minutes. Shared shapes: `person {id, number, name}`, `stage {id, name, type}`,
`application {id, status, appliedOn, source, stage, opening{id,title,status}, candidate{id,name,headline,
isAnonymized,email|null,phone|null}, rejectionReasonId, rejectionNote, startOn, hiredAt, statusChangedAt}`,
`interview {id, applicationId, roundName, mode, status, outcome, startsAt, endsAt, timezone, date, time, endTime,
durationMinutes, location, notes, panel[person], scorecards[], scorecardCount, abilities{edit,cancel,complete,
feedback}}`.

| Page | Props |
|---|---|
| `Me/Show` | `hasProfile`; when true `employee`, `today`, `tasks[]`, `reviews[]` (review rows + `cycle`, `role`, `awaitingMe`), `goals[]`, `enrolments[]`, `probation {endsOn, daysLeft}`/null, `interviews[]` |
| `Openings/Index` | `openings` (paginator of opening rows), `filters`, `statuses`, `departments`, `canCreate` |
| `Openings/Create` | `form {departments, branches, hiringManagers[], pipelines[], employmentTypes, defaultPipelineId, canChangePipeline}` |
| `Openings/Show` | `opening`, `pipeline {id,name,stages[]}`, `applications[]`, `statusOptions[]`, `abilities {edit, changeStatus, viewApplications, addApplication, viewCandidates}`, `form`/null |
| `Candidates/Index` | `candidates` (paginator: candidate row + `applications`, `openApplications`), `filters`, `consentBases`, `canCreate` |
| `Candidates/Show` | `candidate` (detail: + summary, expectedAmount, currency, consentBasis, consentGivenAt, anonymizedAt), `applications[]`, `documents[]`, `notes[]`, `duplicates[]`, `consentBases`, `documentTypes`, `abilities {edit, anonymize, addNote, uploadDocument}` |
| `Applications/Index` | `applications` (paginator), `filters`, `stages[]` (when an opening is filtered), `openings[]`, `statuses` |
| `Applications/Show` | `application`, `opening`, `history[]`, `interviews[]`, `notes[]`, `documents[]`, `offer`/null, `offers[]`, `stages[]`, `rejectionReasons[]`, `abilities {move, reject, withdraw, reopen, hire, addNote, schedule, manageOffer, viewCandidate}`, `offerStatuses`, `employees[]` (panel picker, only when the viewer may schedule), `timezone` |
| `Interviews/Index` | `interviews` (paginator of interview + `candidate`, `opening`), `filters`, `statuses`, `timezone`, `today`, `hasPanelProfile` |
| `Interviews/Show` | `interview`, `application`, `candidate`, `opening`, `modes`, `outcomes`, `employees[]`, `timezone` |
| `Checklists/Index` | `checklists` (paginator), `filters`, `templates[]`, `employees[]`, `kinds`, `canStart` |
| `Checklists/Show` | `checklist`, `tasks[]` (each + `canComplete`, `canSkip`), `abilities {manage, cancel, addTask}`, `employees[]`, `assigneeRoles` |
| `Checklists/Tasks` | `tasks` (paginator of the viewer's pending tasks + `checklistName`, `checklistKind`, `subject`), `today`, `hasProfile` |
| `Probations/Index` | `probations` (paginator + `daysLeft`, `isOverdue`), `filters`, `today`, `reminderDays` |
| `Probations/Show` | `probation` (with `reviews[]`, `daysLeft`), `decisions`, `abilities {decide}` |
| `Cycles/Index` | `cycles[]`, `forms[]`, `completion[]`, `departments` |
| `Cycles/Show` | `cycle`, `form`, `byStatus`, `abilities {edit, launch, startCalibration, close}` |
| `Reviews/Index` | `reviews` (paginator + `cycle`), `filters`, `cycles[]`, `statuses`, `hasProfile` |
| `Reviews/Show` | `review` (+ `selfComment`, `managerComment`, `calibrationNote`, `employeeComment`; manager side null for the subject before release), `cycle`, `form`, `ratings[{itemId, party, rating, comment}]`, `abilities {submitSelf, submitManager, calibrate, acknowledge, assignReviewer}`, `employees[]` |
| `Goals/Index` | `goals` (paginator), `filters`, `form {kinds, employees[], cycles[], ownEmployeeId}`, `canCreate` |
| `Goals/Show` | `goal`, `updates[]`, `abilities {edit, recordProgress, close}` |
| `Courses/Index` | `courses` (paginator + `upcomingSessions`), `filters`, `canManage` |
| `Courses/Show` | `course`, `sessions[]`, `enrolments[]`, `sessionStatuses`, `timezone`, `abilities {manage, enrol}`, `employees[]` |
| `Enrolments/Index` | `enrolments` (paginator), `filters`, `courses[]`, `employees[]`, `ownEmployeeId`, `today`, `canManage` |
| `Analytics/Index` | `from`, `to`, `recruitment`, `workforce {months[], leavers, attritionBp, headcount}`, `reviews[]`, `training` |
| `Settings/Index` | `settings`, `pipelines[]`, `rejectionReasons[]`, `templates[]`, `employees[]`, `options {stageTypes, checklistKinds, assigneeRoles}` |

Dashboard widgets: `talent.self` (`TalentSelfWidget`, `talent.self.view`: `hasProfile, pendingTasks, reviewsToWrite,
reviewsToAcknowledge, activeGoals, openEnrolments`) and `talent.overview` (`TalentOverviewWidget`,
`talent.analytics.view`: `openOpenings, activeApplications, interviewsThisWeek, openOnboardingTasks,
probationsEnding, reviewsAwaitingCalibration, certificatesExpiring`).

## Deployment

Run migrations (`app/Modules/Talent/Database/Migrations`), then `php artisan permissions:sync` and
`php artisan apps:sync --prune`. New permissions are granted to no existing role; owners hold them implicitly.
Nothing is seeded for a workspace: the standard pipeline appears on first use; create checklist templates and a
review form in the settings and cycle pages. A workspace that installs Talent after employees exist gets
checklists and probation tracking only for employees created or changed afterwards (there is no back-fill).

## Not built

A hire-intake contract with HRM (the event is the integration point), contract generation or e-signature,
resume parsing, career-site/job-board publishing, email or calendar invitations to candidates and interviewers,
notification delivery (Phase 8), 360-degree feedback, peer reviews, cascading goals, training attendance
tracking, course materials, learning paths, candidate merge, background checks, and importing candidates from a
file.
