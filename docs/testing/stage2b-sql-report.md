# Stage 2B SQL regression delivery

Status: **test increment implemented and exercised; regression gate remains red for the recorded findings**. No claim of a green or fully resolved 2B prerequisite. Baseline HMS2 `85797156b251bb814ae2e0cea053c080f1b335a6`; pinned SQL `12ba6da237b6a97eb92e7efc53bf81c34844bfa8`.

## Scope and reproduction

Four backend PHPUnit groups use real MySQL 8.4.6, actual migrations and unchanged pinned SQL. No new production hook, product dependency or application change is included. Per the user's delivery instruction, frontend and browser profiles are not rerun in this phase.

```sh
python3 tests/Environment/run.py prepare --profile backend
python3 tests/Environment/run.py pr-check --profile backend --cycles 2 --output /tmp/hms-2b-final
```

The backend command includes the existing foundation checks. A narrower SQL-only selection retains safety and database prerequisites and cannot satisfy full three-profile aggregation:

```sh
python3 tests/Environment/run.py pr-check --profile backend --suite sql --cycles 2 --output /tmp/hms-2b-sql-only
```

| Suite | Primary scenario families | Evidence |
| --- | --- | --- |
| SqlStatusAccess | SQL-01–05 | Returned status/identity, actual role joins, RFID rejection, no usage/money changes on denial, maintainer exception, sign-on/off and Doctrine cross-read |
| SqlInduction | SQL-10 | Card/permission matrix, exact role grant and audit actor, one-time free hour, duplicate/error rollback |
| SqlCharging | SQL-06–09 | Pledged duration, credit limits, elapsed/active time, rounding, persisted money, override, maintenance exemption, repeat/fault atomicity |
| SqlReporting | SQL-11–13 | Verified UTC boundaries, bookings/calendar/channel data, tool/member/legacy summaries, advisory reservation behavior |

## Isolation and reset

`SqlTestCase` reuses guarded application bootstrap and strict transport expectations. Every test/data-provider case recreates the schema, runs real migrations and reloads all 62 procedure/function files and 20 views. It does not rely on an outer rollback around internally committing SQL. Test-owned triggers inject faults locally and disappear on recreation. One case can neither retain committed rows nor alter the next case's routines.

A session-local MySQL `SET timestamp` freezes the actual SQL `UTC_TIMESTAMP()`/`NOW()` clock at 2025-02-15 12:00:00 UTC, verified before every case and reset on teardown. This allows exact inclusive maintenance boundaries without sleeps or crossing the real clock. PHP/other database sessions are not claimed to share this clock. Explicit charge end times make monetary duration assertions deterministic.

Runtime is non-root, read-only, credential-scrubbed, with no published ports, host binds/socket, external route or DNS. Only isolated disposable MySQL is reachable. Google channel tests call a row-upsert procedure only, never a client, subscriber or job. All users/cards/balances/permissions and tokens are synthetic. The disposable server/database default is now `utf8mb4_unicode_ci`, matching the actual tools/roles migration tables. MySQL 8's `utf8mb4_0900_ai_ci` database default made inherited routine string parameters incompatible with those columns (error 1267). Each SQL case verifies database and sign-on parameter collations before exercising behavior. This is test-target alignment, not a production collation change; production compatibility remains a TODO. The authorized temporary test SQL modes remain `ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`; production/strict migration compatibility remains unresolved.

## SQL dependencies and coverage interpretation

Tool access depends on `user`, `profile`, `rfid_tags`, `roles`, `permissions`, `role_user` and `permission_role`; usages on `tools`/`tool_usages`; induction also on `role_updates`; bookings on `bookings`. Charging calls `sp_transaction_log`, `sp_process_transaction` and its allocation helpers, using `transactions` and `purchase_payment`. These dependencies are loaded unchanged, not replaced by procedure stubs. A funded tool purchase exercises actual payment allocation and asserts linked IDs, amount and balance; payment-allocation exhaustiveness outside tool charging remains outside this phase.

`vw_tool_usage` directly joins usage/tool/user. `vw_tool_users` derives tool identity from role display names and requires membership roles. `vw_tool_inductors_maintainers` uses those role/member joins. `vw_member_stats` unions tool usage with access/vending and membership roles; synthetic tests target its tools contribution. `vw_laser_usage` hard-codes tool IDs 1, 14, 36, £3/hour and the legacy laser role names; tests may use these synthetic IDs only in recreated schemas. Broader `vw_snackspace_monthly` additionally needs vending rows to produce a month and combines transaction totals with legacy fixed vending IDs. It is inventoried, not claimed as covered tools reporting.

The current schema has no per-card expiry timestamp and pledged time is a sum of COMPLETE durations with no expiry predicate; state-based destroyed/lost rejection and duration consumption are tested without inventing expiry features. Duplicate tool names/card serial configurations are prevented by the real schema's unique indexes; unreachable multi-match branches are not forced by altering that schema.

These are scenario/branch partitions, not instrumented SQL statement coverage. PHP's unchanged 47-file coverage denominator remains visible, but incidental fixture/bootstrap coverage is not a SQL coverage percentage. SQL statement/branch instrumentation is not provided by PCOV. Any defect assertions remain failing, with nonzero suite/profile exit and persisted state evidence; no expected-failure wrapper converts them into success.

## Verification results and findings

Two initial trials were deliberately cancelled and are not passing evidence: the first exposed an incorrect fixture setter, the second exposed the test database/routine collation mismatch and a fixture referencing a column removed by a later migration. The fixtures now use the actual tool API and current hard-delete booking schema; test-only collation alignment is described above. Cancellation cleanup completed. Their results are excluded from the final counts below.

## Observed contract failures

- **DEF-06 / SQL-05:** A second sign-on returns access granted while the tool is IN_USE. The dedicated contract test requests rejection; whether concurrent sessions are deliberately supported requires a product decision.
- **DEF-07 / SQL-09:** A trigger rejecting the financial insert returns a rollback message, but the usage remains CHARGED with 60 seconds persisted. In a mixed pledged/paid charge, rejecting the paid insert additionally leaves the first zero-value transaction committed and the previously COMPLETE pledge marked CHARGED. `sp_transaction_log` starts a nested MySQL transaction, implicitly committing the caller's earlier work; the later rollback cannot restore it. The ordinary pre-financial-write fault and missing-profile rejection do roll back, distinguishing this boundary from a generic fixture/reset failure.
- **DEF-08 / SQL-08:** Disabled-maintainer use with a -3600-second pledge retains that pledge and leaves balance unchanged, but emits two zero-value transaction records rather than one maintenance entry. The exemption branch falls through into the remaining pledged-time calculation.

These findings have no product fixes in this delivery. The financial assertions compare persisted rows and balance/transaction/allocation snapshots; failures are not skips or expected successes.

## Legacy/compatibility prerequisites

- **LEGACY-01 / SQL-11:** `sp_tool_set_google_channel` raises error 1146 because `tl_google_notifications` is absent. Searching the complete pinned database archive found only the procedure's reference, and no matching table definition or caller was found in HMS application/migrations/config/routes/resources. Do not invent a table schema or call Google to bypass this missing legacy prerequisite.
- **COMPAT-02 / SQL-12:** Actual queries of `vw_tool_users` and `vw_laser_usage` raise MySQL error 1055 under the retained `ONLY_FULL_GROUP_BY` mode. Respectively, membership display name and the month derived from usage start are nonaggregated expressions outside the required grouping. View creation/loading alone did not expose these failures. Other tested tool/member/inductor summaries query successfully. The harness neither relaxes that mode nor rewrites the original view definitions; production mode/version compatibility remains a separate question.

## Final executed results

Date: 2026-10-15. Actual final command:

```sh
python3 tests/Environment/run.py pr-check --profile backend --cycles 2 --output /tmp/hms-2b-aligned
```

It exited **1**, correctly reporting **blocked**, with **two completed cycles**, 42 passing check groups and six blocked groups (status/access, charging and reporting in each cycle). No case was skipped or converted into an expected success. Frontend and browser profiles were not run, as explicitly requested; their earlier 2A results do not substitute for a new full regression run.

| Suite, per cycle | Cases | Assertions | Passed | Failures | Errors |
| --- | --- | --- | --- | --- | --- |
| SqlStatusAccess | 22 | 158 | 21 | 1 | 0 |
| SqlInduction | 11 | 91 | 11 | 0 | 0 |
| SqlCharging | 30 | 226 | 27 | 3 | 0 |
| SqlReporting | 17 | 94 | 14 | 0 | 3 |
| **SQL total** | **80** | **569** | **73** | **4** | **3** |
| Existing FoundationUnit | 3 | 16 | 3 | 0 | 0 |
| Existing FoundationDatabase | 3 | 29 | 3 | 0 | 0 |

Every suite's actual case-name sequence in cycle 2 is the exact reverse of cycle 1, including data-provider partitions. Individual case outcomes match between cycles. Both cycles use fresh workers/networks/MySQL and every SQL case recreates its schema; all safety/credential/configuration/child/network and cleanup checks passed. The SQL failures therefore reproduce after independent resets and reverse ordering; the six foundation cases still make 45 passing assertions per cycle.

The four assertion failures represent three findings: DEF-06 (one repeated-sign-on case), DEF-07 (two financial atomicity cases) and DEF-08 (one maintenance-record case). The three SQL errors represent LEGACY-01 (one missing-table prerequisite) and COMPAT-02 (two grouped views). All 13 SQL scenario families are represented, but affected subcases remain failed/blocked, not covered successfully. Twelve of the thirteen tools/card/permission routine entrypoints execute against existing schema; the Google-channel routine reaches its missing-table error. An additional direct transaction-log call exercises funding/payment allocation. Three reporting views produce asserted rows; two reach compatibility errors. Broader vending reports, races/parallel execution and exhaustive SQL branch instrumentation remain outside the demonstrated coverage.

Six host control-plane tests pass, including new JUnit leaf-count/failure/error/skip accounting and rejection of SQL-only reports as complete aggregates. Product-source/dependency diff and whitespace checks are clean. No GitHub workflow, application change, commit or push was made.

Final generated evidence: `/tmp/hms-2b-aligned/report.json`, its named per-cycle logs and JUnit/Clover artifacts, plus `/tmp/hms-2b-aligned.log`. These session paths are not repository dependencies; the maintained tests, pinned inputs and this report are the enduring record. The optional `--suite sql` selector has controller-level completeness tests; the recorded full backend run is the executed evidence, including foundation and all SQL groups.

## Resource and cleanup evidence

The runtime run sampled a peak filesystem usage of **26.16 GiB**, incremental runtime peak **2.74 GiB**, and minimum available space **3.67 GiB** on the existing 32 GB managed `vfs` filesystem. The highest observed aggregate container memory was approximately **932 MiB**, across 119 successful samples; this is sampled usage, not an exact instantaneous peak. Existing container memory/process limits remain enforced. No larger worker, daemon change or relaxed isolation was required.

Cleanup checks passed in both cycles. Post-run Docker inspection found no containers and only the standard bridge/host/none networks. The prepared backend source/dependency volume and public runtime image/cache remain registered for quick reruns; they contain no runtime database fixtures or production secrets. No broad prune was used.

The next delivery can build on passing SQL fixtures/reset support, but must retain the red findings and known prerequisites. Decisions about correcting behavior, retiring the legacy channel routine or changing supported reporting modes belong in separately authorized product changes; the task's final question section records them. A full three-profile regression remains a later verification step.
