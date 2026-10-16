# Stage 2G first-draft consolidation

Status: first-draft consolidation delivered and verified. All three profiles completed both cycles; the aggregate remains red for recorded product/legacy findings. This document is the review entrypoint for the unchanged-product tools regression draft. It does not approve product defects, add application hooks or enable GitHub CI. Whole Stage 2 acceptance remains open while required failures remain unresolved.

## Reproduce and review

Baseline: HMS2 `190da868e5edba5baacd3ace8e0eff51c5edd073` on `tools-test-harness`, SQL `12ba6da237b6a97eb92e7efc53bf81c34844bfa8`. Product code/dependencies are unchanged since the authorized Stage 1 migration identifier fix. MySQL 8.4.6 retains the authorized temporary test-only SQL modes, including ONLY_FULL_GROUP_BY; production compatibility remains unconfirmed.

From a checkout of this branch, require Python 3, Git, the local Docker daemon, public dependency/image access and a trusted CA for preparation; no local PHP/Node/MySQL, real service credentials, private assets or deployed HMS instance is needed. Read the [runner guide](../../tests/Environment/README.md) for platform/resource prerequisites and supported selectors, then run:

```sh
python3 -m unittest discover -s tests/Environment -p test_runner.py -v
python3 tests/Environment/run.py clean --profile all
python3 tests/Environment/run.py pr-check --profile all --cycles 2 --output /tmp/hms-2g-pr-check-complete
cp /tmp/hms-2g-pr-check-complete/report.json /tmp/hms-2g-pr-check-complete/combined-run-report.json
python3 tests/Environment/run.py aggregate --output /tmp/hms-2g-pr-check-complete
```

`clean` only releases registered harness resources; it is useful for this clean-preparation audit and is not mandatory before each iteration. Combined execution prepares each profile in turn, retains its bounded artifacts, tears it down and evicts its owned preparation before starting the next. Backend SQL/PHP/HTTP and frontend known failures must remain nonzero; browser success cannot override them. Run the copy/independent aggregation even after the expected nonzero regression result; do not join these commands with `&&`. The copy preserves combined-run resource measurements because standalone aggregation rewrites the root report from profile evidence. No interactive production configuration should be supplied.

Review the [scenario inventory](tools-regression-inventory.md), [harness architecture/extension guide](regression-harness.md), [concrete future PR workflow](github-pr-workflow-spec.md) and [Stage 3/4 gap handoff](stage3-isolation-and-coverage-plan.md). The latter distinguishes a proposed MQTT factory seam, local websocket infrastructure, product findings, database compatibility and additional tests not requiring hooks. Nothing in this stage implements those later changes.

## Consolidation changes and trial accounting

Stage 2G updates maintained instructions/inventory/task records and future automation/enabling specifications. Runner metadata now reports delivered scope and named transport deferrals instead of hardcoding Stage 2G as eternally pending. A cold-preparation resource fix invokes the existing ownership-checked PHP/Composer build-input release before pulling MySQL, after registering imported build-cache IDs. Final runtime/source/MySQL and borrowed resources remain protected. No storage-driver/daemon change, global prune, direct Docker-directory manipulation or relaxed isolation is used.

An initial preparation was cancelled to correct stale metadata before accepted evidence. The next trial reached backend application/schema/routine/foundation checks but reported zero available host disk during MySQL startup. It was explicitly cancelled (exit 130); runtime cleanup passed and incomplete reports remain under `/tmp/hms-2g-pr-check-final`. That trial is not a completed regression run. The final clean rerun after the resource correction uses `/tmp/hms-2g-pr-check-complete`. This was an operator cancellation on the reported disk measurement, not an observed ENOSPC exception or a completed test failure. Cold-build/preparation costs must be reported separately from cached preparation; successful execution is not evidence that every smaller host will fit.

## Interpreting the draft

Scenario families cover FND-01–06, SQL-01–13, PHP-01–18, HTTP-01–07, UI-01–05 and selected WEB-01–03. Family traceability does not claim exhaustive partitions or successful contracts. Manager/route assertions and component/actual-browser assertions complement each other; they must not be merged into invented feature percentages. Existing layer reports retain the rationale for individual assertions and historical findings.

Final proposed gates remain Stage 4: core PHP lines at least 90%, full agreed PHP scope 85%, supported core decision branches 80%, and explicit critical-scenario/defect dispositions. PCOV supplies PHP lines, not branches; SQL scenario coverage, compiled-SFC Istanbul and actual browser cases remain separate evidence. Execution in a failing case counts toward line coverage but does not prove correctness. No expectation wrapper, skipped difficult case, narrowed allowlist or compatibility relaxation closes a finding.

## Final evidence

The final combined command and standalone aggregation both exit **1/blocked**, correctly. All reports share the baseline above, product digest `65180871f036a42614559a7b206c573ea5bff4df482318776d5e624e7e7906c8` and current tooling digest `690284b7a4741708e03dc3039e37f7130b0616fe7f8eae11e3de169535562bdf`. Profile reports/current inputs match. Every required check is present exactly once per cycle; no partial selection, duplicate, stale identity, missing layer or smoke-only substitution is accepted.

| Profile | Completed cycles | Passing runner checks | Blocked runner checks | Disposition |
| --- | ---: | ---: | ---: | --- |
| Backend | 2 | 54 | 14 | Recorded SQL/PHP/HTTP findings |
| Frontend | 2 | 20 | 2 | Recorded component contracts |
| Browser/integration | 2 | 100 | 0 | All selected actual journeys and prerequisite/state/safety checks pass |
| **Combined** | **2 per profile** | **174** | **16** | **190 checks accounted for; gate remains red** |

Runner-check counts are groups, not individual test cases. Leaf regression counts below are **per cycle**, identical in both cycles; incidental tooling smoke cases are separate.

| Layer | Cases | Passed | Assertion failures | Test errors | PHP assertions |
| --- | ---: | ---: | ---: | ---: | ---: |
| Foundation | 6 | 6 | 0 | 0 | 46 |
| SQL | 80 | 73 | 4 | 3 | 569 |
| Backend PHP | 100 | 94 | 6 | 0 | 451 |
| Routed HTTP | 64 | 61 | 3 | 0 | 763 |
| **Backend total** | **250** | **234** | **13** | **3** | **1,829** |
| Frontend components | 98 | 91 | 7 | 0 | — |
| Actual browser journeys | 8 | 8 | 0 | 0 | — |

Frontend has 888 passing expectations per cycle, not a count of all executed assertions. Two frontend tooling cases also pass each cycle. Browser cases are desktop/mobile visibility, booking lifecycle, native grant and denials; they are included in the 100 browser check groups alongside resets, independent state verification and safety prerequisites. No regression cases are skipped; no suite-load/report-validation/coverage error occurred. All 15 host controller tests pass.

All 16 backend suites have exact reverse leaf-case order and identical named outcomes/assertion counts. All four frontend groups change order under seed 42 with identical outcomes/passing-expectation counts and coverage. Browser cycle two reverses all eight journey names exactly. Both cycles recreate workers/networks/MySQL; browser cases independently reset synthetic sessions/domain state. This is evidence for the exercised orders, not every possible interleaving.

### Findings and coverage disposition

No new product finding was introduced by consolidation. DEF-01–04/06–16 remain ordinary red contracts across their owning layers; DEF-05 remains the characterized reservation-policy decision. LEGACY-01 contributes one SQL error (missing channel table), and COMPAT-02 contributes two (grouped views under retained ONLY_FULL_GROUP_BY). COMPAT-01 strict-mode compatibility and COMPAT-03 displayed precision deprecation remain recorded. No product fixes, new expected-failure wrappers, skips or mode relaxations were used.

PHP union coverage across 32 Clover artifacts retains all 47 files: **224/227 core lines (98.68%)**, **1041/1344 wider-scope lines (77.46%)**. The wider 85% proposed target is unmet. Largest unexecuted shared areas include Role, DoctrineUserRepository, RoleManager, User and role/audit repositories; HmsPassportUserRepository (0/7) and AuthorizeAll (0/5) remain wholly uncovered. Three core BookingManager statements remain uncovered. Useful further scenarios need scope review rather than getter-only tests or invented routes.

Fixed seven-SFC coverage is **508/508 statements, 507/507 lines, 137/137 functions and 188/208 branches (90.38%)** in both cycles, including compiled render glue and failing tests. Neither this nor PHP line coverage measures SQL/Blade/CSS/external plugins or live delivery. Selected real browser journeys add integration evidence, not a percentage. Live MQTT/websocket, broader browser/auth/reporting partitions and final acceptance remain explicitly assigned in the gap handoff.

### Safety, artifacts and resource evidence

All safety/prerequisite/cleanup checks pass independently in every profile/cycle, including the browser's application prerequisite. Production/config/credential/cache/sentinel rejection runs before unsafe setup; no-network/private-network HTTP/DNS/raw TCP/SMTP/MQTT/child/cross-bridge probes and actual browser HTTP/websocket denial pass. Strict transport expectations remain active. Actual web requests use synthetic local credential/session/Passport-cookie/CSRF flows; no production secrets or private assets are required.

Every browser reset/state check passes, and the deliberate empty-lifecycle verification exits 1 in each cycle. Booking journeys leave zero bookings and the exact create/change/change/cancel event sequence; grant journeys verify the exact member/role/acting-administrator audit. All journeys preserve zero usages/financial transactions and four unchanged synthetic balances. No live queue worker, remote broadcast/MQTT delivery, scheduler, hardware or production database is exercised.

A bounded scan of **329 JSON/XML/log artifacts** found no private-key block, bearer-header or JWT pattern. This complements source/credential exclusion and synthetic fixture review; it is not a universal secret detector. Logs and machine-readable leaf/coverage/state evidence remain under `/tmp/hms-2g-pr-check-complete/{backend,frontend,browser}`. The original combined report with global resource samples is `combined-run-report.json`; independent reaggregation is `report.json`. Generated artifacts/caches remain ignored.

| Profile/step | Peak used disk GiB | Minimum available GiB | Incremental peak GiB | Observed container memory |
| --- | ---: | ---: | ---: | --- |
| Backend cold preparation | 27.80 | 2.04 | 5.47 | Unmeasured (0 samples) |
| Backend execution | 29.30 | 0.53 | 2.74 | 1087.30 MiB, 260 samples |
| Frontend preparation | 22.35 | 7.48 | 0.03 | Unmeasured (0 samples) |
| Frontend execution | 22.36 | 7.47 | 0.46 | Unmeasured (0 samples) |
| Browser cold preparation, including PHP/MySQL | 27.30 | 2.54 | 6.24 | Unmeasured (0 samples) |
| Browser execution | 30.32 | **0.00** | 4.52 | 1053.73 MiB, 16 samples |

Global incremental peak was 8.00 GiB above the combined-run starting point. Samples include borrowed host contents and retained cache costs; they are not resource guarantees. At the browser peak `statvfs` reported zero available blocks while free blocks remained positive; all actual test/report writes and cleanup still succeeded. This is inadequate measured headroom for a general capacity promise. Use a roomier host for review/CI (the workflow proposes at least 12 GiB initially free) and validate its budget; do not change storage drivers or depend on reserved space. Resource measurement is not an implemented minimum-free-space admission gate.

Final inspection finds no test/preparation containers or private networks, only Docker's built-in networks. Default combined execution evicted the owned profile caches, including the browser's backend dependency; shared tiny probe/download cache or still-referenced registered build records may remain. No unrelated resource was removed. Product comparison against Stage 1 and whitespace checks pass.

Stage **2G consolidation is delivered**. Stage **2 acceptance remains open** for 2B–2E findings and later coverage/dispositions; Stage 3/4 hooks/tests and GitHub activation have not been done. The task stays in `docs/tasks`. No commit or push is included.
