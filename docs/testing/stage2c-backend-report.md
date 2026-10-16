# Stage 2C backend regression delivery

Status: test increment implemented and exercised; six PHP contract findings remain open, so the required gate remains red. Product baseline `5aad773f` on `tools-test-harness`; pinned SQL `12ba6da237b6a97eb92e7efc53bf81c34844bfa8`. Application source, migrations, original SQL and product dependency manifests remain unchanged.

## Delivered boundaries

| Suite | Scenario families | Actual boundary |
| --- | --- | --- |
| BackendUnit | PHP-01–06, PHP-08–13, PHP-15–18 | Tool/booking/usage factories, manager rules, permission/time/count matrices, explicit repository/event recording ports, entity JSON, queued-listener serialization, statistics and collector values |
| BackendRepository | PHP-10–11, PHP-14, PHP-16 | Real Doctrine/MySQL interval, current/next/future/type/user/tool queries, cancellation, usage/free-time and UTC/London DST persistence |
| BackendLifecycleAudit | PHP-02–07 | Real tool/role/permission creation and reuse, grants/revokes, ACL authority split, audit actor/system attribution, constraints, rename and removal |
| BackendMembershipRetention | PHP-07 | Actual nonpayment/underpayment listener logic, current/young roles, retained tool user role versus elevated grants, notification-clear method, explicit recorded mailable, non-member no-op |
| BackendReportingWorkflow | PHP-12–15, PHP-17–18 | Real booking create/update/cancel persistence with selected event recording, production registration, actual month statistics/cache expiry and metric values |

The unit group boots no application kernel and uses no network or database. Repository doubles are strict and only configured for each case's boundary. Manager permission decisions are explicitly doubled there; real persisted role joins and Laravel Doctrine ACL permissions are tested in the database lifecycle suite. Request-boundary Gate registration and middleware remain owned by 2D; the lifecycle cases do not assume newly created permissions were already registered during the earlier application bootstrap. Unit synthetic entity IDs are assigned only in test fixtures.

Database groups reuse the guarded SQL support and actual migrations/routines. Every case recreates the schema; no outer transaction is trusted around application repositories that flush or routines that commit. PHP and MySQL clocks are verified/controlled independently and restored, with actual UTC persistence tested across both London DST transitions. Financial SQL exhaustiveness remains owned by 2B.

## Commands and selection

```sh
python3 tests/Environment/run.py prepare --profile backend
python3 tests/Environment/run.py pr-check --profile backend --cycles 2 --output /tmp/hms-2c-final
# After the test-only ACL-boundary correction, repeat all PHP backend/foundation groups:
python3 tests/Environment/run.py prepare --profile backend
python3 tests/Environment/run.py pr-check --profile backend --suite backend --cycles 2 --output /tmp/hms-2c-php-final
```

This cumulative backend command includes the completed foundation and SQL suites plus the new backend groups. Existing SQL failures are retained. Frontend/browser are not run, following the user's preference to defer full three-profile regression.

For focused iteration, `--suite backend` selects foundation plus the 2C PHP groups and retains safety/database prerequisites. `--suite backend-unit` runs foundation unit and rule cases without starting MySQL; `--suite backend-integration` runs database foundation and the four persistence/workflow groups without unit or SQL business suites. `--suite sql` retains the 2B selection. Only default `--suite all` may satisfy complete three-profile aggregation. The controller tests reject all four partial selections as a complete aggregate. Selection does not change the reviewed preparation snapshot or weaken runtime guards.

Named PHP suites remain in `tests/regression.xml`. The original Pest example is invoked explicitly by path for its smoke check, preventing legacy directory discovery from running the new unit suite a second time outside its authoritative regression configuration.

## Events, audit and transport limits

Production event registration is inspected before any recording. Most integration cases record only named outward-bearing event types; unrelated events remain on the application dispatcher. The audit group replaces queued/outbound-bearing handlers for RoleCreated/UserAddedToRole/UserRemovedFromRole only, then registers local closures executing the real RoleUpdateLogger synchronously with actual repositories. Audit persistence, actor IDs, the approval exclusion and nullable system actor are asserted. This proves local event/audit behavior, not delivery by a running queue worker or execution of unrelated directory/Discord listeners.

Membership tests execute both actual revocation listeners and real RoleManager/repositories. Their banking metadata, synthetic bank and box lookups are explicit doubles; an actual notification object is cleared through its real method and its save is recorded. A strict PendingMail double accepts only the expected synthetic mailable. Mail rendering/delivery and the broader banking notification pipeline are not claimed.

The real CallQueuedListener wrapper is serialized/rehydrated with a booking event; payload identity/channel/JSON survive. Its handle method and MQTT subscriber are never executed. Direct MQTT construction remains HOOK-01, deferred until the separately authorized isolation hook. Event recording and queue serialization do not count as actual websocket/MQTT integration.

All runnable work remains non-root/read-only, with explicit synthetic environments, independent credential/configuration/child/network denial and schema-scoped disposable MySQL. No scheduler, external service, host socket/source bind or published port is added. No test invokes a real mail, payment, MQTT, Google or Discord client.

## Findings and decisions

Existing suspected defects are exercised by dedicated failing contracts: booking-update clash rejection (DEF-01), independent before/after event values (DEF-02), and role identity after internal tool rename (DEF-04). Pure restriction matrices characterize the current per-tool boundary; a separate consistency assertion requires a direct normal-booking call to agree with the capability summary's general tools.book gate. That is a manager/middleware contract decision, not proof of a routed authorization vulnerability; routed checks belong to 2D.

DEF-09 records the direct manager/capability inconsistency above. DEF-10 reproduces acceptance of an end before the start when editing an ongoing booking with its start unchanged. DEF-11 reproduces a referenced-tool deletion failing its database constraint after its roles have already been soft-deleted: the tool survives with its roles removed. COMPAT-03 records a PHP 8.4 float-to-integer precision deprecation in statistics duration formatting (`GenerateToolStatistics.php:115`); the reporting assertions pass, and deprecations are displayed rather than made a new failure policy. No expected-failure wrapper, skip or product change is used to make these contracts pass.

## Verification and coverage

Final focused verification (`/tmp/hms-2c-php-final/report.json`) completed both cycles. Every case ran in exact reverse order in cycle two with identical outcomes and assertion counts. Per-cycle results follow; no cases are skipped.

| New suite | Cases | Pass | Assertion failures | Errors | Assertions |
| --- | ---: | ---: | ---: | ---: | ---: |
| BackendUnit | 70 | 66 | 4 | 0 | 175 |
| BackendRepository | 12 | 12 | 0 | 0 | 85 |
| BackendLifecycleAudit | 10 | 8 | 2 | 0 | 103 |
| BackendMembershipRetention | 5 | 5 | 0 | 0 | 58 |
| BackendReportingWorkflow | 3 | 3 | 0 | 0 | 30 |
| **Stage 2C** | **100** | **94** | **6** | **0** | **451** |

The six contract failures are DEF-01/02/04/09/10/11. Their assertions remain failing; no approved variance or product fix is implied. Existing foundation contributes six passing cases / 45 assertions. SQL retains its previous 80 cases / 569 assertions, 73 pass / four assertion failures / three errors (DEF-06/07/08, LEGACY-01 and COMPAT-02); SQL errors are not new PHP fixture errors. The final focused run contains 106 named PHP regression cases, 100 pass / six assertion failures / zero errors and 496 assertions per cycle, excluding the separate legacy Pest smoke check. Combining these final PHP results with the independently repeated unchanged SQL groups accounts for 186 cases: 173 pass / ten assertion failures / three errors and 1,065 assertions per cycle. This is reconciled evidence across the two recorded snapshots, not a claim that a final single all-suite command passed.

The cumulative `/tmp/hms-2c-final/report.json` completed two cycles and exits 1/blocked (48 passing groups, ten blocked). Its SQL suites have identical per-case outcomes/assertion counts in exact reverse order, retaining the 2B findings above. Its second PHP lifecycle cycle also exposed the harness's pre-fixture Gate-registration assumption described below; that run is **not** accepted as final PHP repeatability evidence. The correction changes only the lifecycle ACL assertions; SQL cases/runner behavior remain unchanged. The subsequent focused `--suite backend` run repeats all PHP foundation and 2C groups on the corrected snapshot. The focused report has 46 passing groups and four blocked groups (unit and lifecycle once each per cycle), exits 1 as required, and matches the current product/tooling checksums. All prerequisites, safety checks and cleanup pass. The seven control-plane tests pass, including all partial-selection aggregate rejection and coverage-union checks. A separate two-cycle unit-only run also exercised the no-MySQL selector before the lifecycle-only fixture corrections; the final focused run repeats those unchanged unit cases. Database groups are exercised by the focused backend command; the database-only selector is additionally checked by the controller selection assertions. No three-profile aggregate is claimed.

Cumulative-run sampled filesystem peak: 26.17 GiB, minimum free: 3.67 GiB, incremental peak: 2.74 GiB; observed aggregate container memory peak about 957 MiB over 167 samples. These are sampled host/runtime observations on the existing managed `vfs` daemon, not resource guarantees. Exploratory/cancelled trials are excluded from final evidence. They exposed a missing inverse collection in a pure unit fixture, duplicate discovery through the legacy Pest smoke command, reuse of a detached Doctrine tool after identity-map clearing, and a Gate assertion relying on permission names left by an earlier case before application bootstrap. These test-only issues were corrected (including reloading the tool before manager status changes and checking persisted ACL permissions directly rather than assuming same-request Gate registration); interrupted runs cleaned up their owned services. Their partial results are not counted as completed verification cycles.

The fixed 47-file PHP source scope is retained, including uncovered files. Coverage is a union of executable statement lines across suites from the same baseline, never a sum of repeated runs. Core scope is the two managers and three tools factories; the full allowlist also includes repositories/entities, controllers/events/listeners and selected role/auth support. PCOV provides PHP line coverage, not branch, SQL, JS, real queue-worker or browser coverage. Planned HTTP/component/browser layers remain visible gaps.


### Final PHP coverage and remaining gaps

The final focused report unions 14 Clover artifacts across both cycles: **224/227 core lines (98.68%)** and **728/1,344 full-allowlist lines (54.17%)**, across all 47 allowlisted files. The new 2C suites alone cover 725 full-scope lines; foundation contributes three more. Coverage includes failing tests, not just successful contracts. The earlier cumulative report covers 730 lines but uses the superseded Gate assertion and is not substituted for the final focused figure.

| Core source | Covered / executable lines |
| --- | ---: |
| ToolManager | 92 / 92 |
| BookingManager | 101 / 104 |
| ToolFactory | 14 / 14 |
| BookingFactory | 8 / 8 |
| UsageFactory | 9 / 9 |

The three uncovered core statements are the induction/maintenance invalid-time return paths and maintenance-edit maximum-length calculation (`BookingManager.php:118,164,217`). Common time-validation branches are tested through normal bookings; these specific callers remain gaps. The three concrete tools repositories cover all 146 executable lines, which does not imply exhaustive query/input combinations. Major wholly uncovered files include the tools web/API and member-search controllers, Passport user repository and selected authorization/feature middleware; those are principally 2D. Other support/entity branches remain partially covered. The wider 85% final target has not been met. MQTT/actual queue workers, broadcast delivery, components/browser, branch and SQL coverage are not inferred from PHP line coverage.

Final focused-run sampled filesystem peak: **26.17 GiB**, minimum free **3.67 GiB**, incremental peak **2.74 GiB**; observed aggregate container memory peak about **925 MiB** over 48 samples. Both cycles passed cleanup. Final inspection found no containers and only Docker's built-in `bridge`, `host`, `none` networks. Registered backend preparation caches remain for quick reruns; no daemon/driver changes or broad pruning were used.

The product digest remains `65180871f036a42614559a7b206c573ea5bff4df482318776d5e624e7e7906c8`. Current tooling digest matches the final focused report (`f28ab11aad2ced37ea3f5cf4e1111ea7c7ceae5844424ef78a62165147e56606`). Application/configuration/routes/migrations/original SQL and product dependencies have a clean diff; `git diff --check` passes. Logs contain synthetic fixtures and reserved example destinations. No workflow activation, product fix, commit or push is included.
