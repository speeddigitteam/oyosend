# Performance

One measurement session on a developer machine, with a dependency-free tool that ships with the application.
These numbers are a baseline and a way to spot a missing index. They are **not** a capacity statement.

## 1. The tool

```bash
DB_DATABASE=oyosend_x_testing php artisan ops:benchmark --rows=20000          # seeds, times, explains
DB_DATABASE=oyosend_x_testing php artisan ops:benchmark --detail --runs=7      # every SELECT with its time and plan
DB_DATABASE=oyosend_x_testing php artisan ops:benchmark --cleanup              # delete the workspace afterwards
```

`app/Platform/Console/BenchmarkCommand.php`. It refuses to run in production or unless the database name ends in
`testing`. It creates (or reuses) a workspace `perf-bench`, bulk-inserts `--rows` contacts and half as many
support tickets (`--tickets=` overrides), then runs each scenario inside that tenant's context so the model
scopes apply. Each scenario runs `--runs` times (the first, warm-up run is discarded); the table reports the
median and minimum wall time, the number of SQL statements, and the sum of `EXPLAIN`'s `rows` estimate over the
SELECTs it issued. It flags a full scan (`type=ALL`, more than 1 000 rows), a filesort or a temporary table.

What it measures: the Eloquent contact list queries (the controller's query shape), the ticket list, status and
SLA queries as query-builder SQL, and the support report service (`SupportReports::overview`) as shipped. What
it does not: HTTP, Inertia serialisation, authorisation (`RecordAccess`), sessions, Redis, queue throughput, or
anything with more than one workspace in the table.

## 2. Result

Environment: Windows 10, Laragon, PHP 8.4.13 (no opcache tuning), MySQL 8.0.30 with default configuration, one
machine that was at the same time running other test databases and test suites. `oyosend_hard_testing`,
one workspace, 20 000 contacts and 10 000 tickets. Median of 6 timed runs.

| Scenario | Median | Statements | EXPLAIN |
|---|---|---|---|
| Contacts: first page (25, ordered by name), with the pagination `count(*)` | 68 ms | 2 | page query 1 ms on `(tenant_id, first_name, last_name)`; the `count(*)` is ~65 ms and is almost all of it |
| Contacts: page 401 (offset 10 000) | 123 ms | 2 | same indexes; MySQL walks 10 000 index entries |
| Contacts: search `%rahim%` over name, email and phone | 68 ms | 1 | scans every contact of the tenant on the name index (a leading wildcard cannot use an index) |
| Contacts: exact email | 1.0 ms | 1 | `(tenant_id, email)` |
| Tickets: open or new, newest activity first (25) | 1.9 ms | 1 | `(tenant_id, last_activity_at)` backward scan |
| Tickets: count by status | 12 ms | 1 | covering index |
| Tickets: SLA due scan (limit 100) | 4.2 ms | 1 | `tenant_id_number_unique` chosen; fast on 10 000 rows |
| Tickets: newest by `created_at` (25) | 1.4 ms | 1 | `(tenant_id, created_at)` backward scan |
| Support overview report, last 30 days (about 6 000 tickets in range) | 9.0 s | 7 | each ticket query 50 to 60 ms in MySQL; the rest is PHP, see below |

No scenario was flagged as a full scan, filesort or temporary table, so **no missing index was found** for these
queries and no migration was changed. `EXPLAIN` row figures are the optimiser's estimates (it reported about half
the real row count for both tables).

## 3. What it exposed (not fixed here)

1. **The support overview report is CPU-bound in PHP.** `SupportReports` streams every ticket in the range with
   `cursor()` as full Eloquent models (`select *`, all timestamp casts) in four separate loops. At about 6 000
   tickets the volume section took 6.8 s, backlog 2.1 s, SLA 2.1 s and times 1.7 s when run alone. The database
   part is about 0.25 s. A fix that would be local to that file: select only the needed columns or aggregate in
   SQL (`group by date(...)`, `count(*)`). It was left alone because the file is under active change and the
   report is module business logic; the number is a reason to schedule that work, not an artefact of the tool.
   On Linux with opcache the factor will be smaller; it was not measured there.
   *Later change, not re-measured:* `SupportReports` was subsequently rewritten to aggregate in SQL (grouped
   `COUNT(*)` queries, and a `sections` argument so a report provider scans only what it shows). The 9.0 s figure and
   the timings above describe the implementation that was benchmarked; run `ops:benchmark` again before quoting a
   number for the current code.
2. **Pagination `count(*)` dominates the contact list** at 20 000 rows (65 of 68 ms). It grows linearly with the
   workspace. Cursor or simple pagination is the documented policy for large lists; the contact list still counts.
3. **Deep offset paging** costs 100 to 170 ms at offset 10 000 and grows with the offset.
4. **Name search uses a leading wildcard** and so reads the whole tenant partition of the index. Fine at 20 000
   contacts (68 ms); the Scout `database` driver is not what this list uses.

## 4. Caveats

- One tenant. Real behaviour with thousands of tenants sharing a table (buffer pool pressure, `tenant_id`
  selectivity, statistics) is untested.
- One machine, Windows, a busy MySQL with default settings and no tuned buffer pool; times are noisy
  (a rerun of the same report took 7.6 s). Compare runs of the same machine only.
- Warm cache: the data fits in the buffer pool after the seed. Cold-cache reads are not represented.
- No Redis, no Horizon, no concurrency. Nothing about queue throughput or per-tenant fairness was measured
  (`docs/DEPLOYMENT.md` section 12 lists those gaps).
- Synthetic data: uniform names, no companies, no ticket messages, no attachments, no audit-log volume.
- Larger sizes (100 000 rows and up) were not run. Only contacts, tickets and the support report were covered;
  CRM, invoicing, HRM, payroll and the other reports were not benchmarked.
