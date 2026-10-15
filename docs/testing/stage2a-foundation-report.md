# Stage 2A foundation delivery report

Status: **complete for the delivered foundation/tooling scope**. The initial monolithic capacity failure documented below was superseded by the implemented and verified three-profile split; see the [split verification report](suite-split-report.md). Each profile passed two clean/reordered cycles, independently and cumulatively: 80 required checks passed, with two explicit actual-HMS-journey deferrals. Business regression suites remain planned.

The following initial-attempt evidence is retained as history, rather than replaced with passing results.

Date: 2026-10-15. Baseline HMS2 `f08bab4726307c8a75bbcb5a07c10b0bd9a51a45`, SQL submodule `12ba6da237b6a97eb92e7efc53bf81c34844bfa8`. The application, production configuration, mappings, routes, migrations, product dependency manifests and pinned SQL remain unchanged by 2A. Stage 1's authorized identifier fix and temporary non-strict test modes carry forward. No GitHub CI configuration was created.

## Delivered scope

- [Scenario inventory](tools-regression-inventory.md): stable IDs and named case partitions for SQL, PHP, HTTP, Vue and browser capabilities; owning chunks, proposed test locations, dependencies, eligibility and a separate defect register. Business scenarios remain planned; missing MQTT injection and full browser asset prerequisites remain explicit deferrals/blockers.
- [Harness instructions](regression-harness.md): reusable bootstrap, deterministic synthetic builders, clock cleanup, strict transport doubles, reset choices, suite conventions and adding another domain.
- `tests/Support`: one preflight/bootstrap/SQL loader shared with Stage 1, fixture builders, scoped PHP clocks, strict recording expectations through existing framework bindings, two database reset paths and a guarded PHPUnit entrypoint. New test files are an explicit source-preparation allowlist with independent checksums.
- `tests/regression.xml`: two delivered PHPUnit suites; fixed 47-file PHP coverage allowlist, including five core tools manager/factory files, with uncovered files retained. No product dependency or framework migration was needed.
- `pr-check`: cumulative safety/smoke plus foundation execution, fresh services for each cycle, default then reverse test order, explicit pending chunks and full-asset deferrals, JUnit/Clover artifacts and a nonzero result for required failures/missing reports.

## Reproduction and evidence

```sh
# Historical monolithic attempt; current reproduction is in suite-split-report.md.
python3 tests/Environment/run.py prepare
python3 tests/Environment/run.py pr-check --cycles 2 --output /tmp/hms-2a-final
```

Preparation was exercised with the declared pinned PHP 8.4.13/MySQL 8.4.6/browser images and locked public dependencies, both from a clean PHP tooling build and during source-only iterations. Installation never bootstrapped HMS or ran Composer/npm lifecycle scripts. A source-only dependency cache verifies pinned images, Dockerfile and both Composer inputs, replaces the entire filtered source and regenerates autoloading without scripts/plugins; it retains a stable dependency base instead of chaining source revisions.

The final attempted command exited **1**, correctly reporting `incomplete`: **one of two cycles completed**, with **20 passing checks, three blocked checks and one explicit full-asset deferral** across the attempted run. All six new foundation tests passed in cycle 1 (**45 assertions**, zero failures/errors/skips), and JUnit/Clover artifacts were retained. The frontend-unit and browser containers could not be created (`no space left on device`); cycle 2 then failed its canary control before reaching the reverse-order foundation run. Both cleanup checks passed and no smoke containers or private networks remained.

| Foundation suite, cycle 1 | Tests / assertions | PHP line coverage over the full 47-file allowlist |
| --- | --- | --- |
| FoundationUnit | 3 / 16, all passed | 51 / 1,344 (3.79%) |
| FoundationDatabase | 3 / 29, all passed | 93 / 1,344 (6.92%) |

These are separate, incidental application-coverage figures from infrastructure checks, not a merged total or business regression coverage. The foundation cases prove the support behavior described below. At this initial attempt, reverse-order/fresh-service repeatability remained unverified; the later split verification supplies that evidence. Earlier failing trial results are not counted as passing evidence.

Generated evidence is under `/tmp/hms-2a-final` (`report.json`, logs and the named JUnit/Clover artifacts); it is not a maintained repository artifact. The maintained commands, case IDs and this report preserve reproduction and interpretation. The prior Stage 1 frontend/browser success remains historical evidence, not a substitute for this failed cumulative rerun.

## Reset and isolation evidence

The ordinary reset test persists a synthetic user/role/permission/card/tool/content/meta graph and cross-reads its committed state through Doctrine and Laravel. It then changes Doctrine entities and Laravel rows in separate transactions, injects a deliberate callback failure, and checks both rollbacks and EntityManager clearing. A successful callback is also rolled back. Uncommitted cross-connection reads are not assumed to work.

The stronger reset test creates a test-owned procedure with an internal commit, proves the row change is visible to Laravel while the original Doctrine object is stale, then recreates the disposable schema through real migrations and pinned SQL. It checks removal of committed fixture rows and the test-owned procedure, cleared ORM state, restored migration metadata/routine availability and reuse of the same synthetic tool identity. This proves reset infrastructure; actual charging business scenarios remain planned in 2B. ORM connections are reconnected after migration DDL to avoid stale transaction bookkeeping.

The strict transport check rejects unexpected mail, notification, broadcast, queue, bus and HTTP calls and records an explicitly permitted bus dispatch without contacting a service. Domain events and real middleware are not globally disabled. Directly constructed SDK/MQTT clients are not claimed to be intercepted; the existing network-level denial remains independent and those notifier tests are deferred pending their hook.

Product, harness and regression-file checksum corruption were separately rejected before any Docker call, using isolated temporary preparation manifests (`/tmp/hms-2a-checksum-negative.json`). Existing pre-bootstrap configuration/credential/cache negatives, no-network/private-network/canary/child/browser probes, non-root/read-only/capability/mount checks, system-schema access denial and per-cycle cleanup remain in the cumulative command.

## Findings from implementation trials

Early trial failures were in the new test infrastructure, not accepted product behavior: a nonexistent user role-adder was replaced by the actual model collection API; DBAL's native PDO wrapper was used for transaction-state checks; post-migration connections were refreshed; and reports were streamed before container shutdown because stopped-container `docker cp` cannot recover unmounted tmpfs files. Only fixed, size-bounded report names are decoded outside the container; tests receive no host output mount.

Source iteration exposed the small Docker `vfs` host's storage cost and an image-chain cache issue. Identified obsolete harness images/build cache were removed, the source-only cache was changed to retain a stable dependency base, and the final attempt used a clean tooling build. Even after standard removal of identified unused harness images/build cache and the no-longer-needed Composer build image, this 32 GB `vfs` host had only about 1.5 GB available and could not complete the cumulative run. No privileged maintenance container, storage-driver change, daemon bypass or isolation relaxation was used. A host/session with sufficient free Docker storage is needed to repeat the declared command; stopping required suites or treating the storage failure as success is not an acceptable substitute. A container-creation failure now preserves the original launch diagnostics and fails the named check rather than losing its log during inspection. Required failures remained nonzero; trial results are not counted as final passes.

## Limits and next delivery

- This is foundation evidence, not high tools business coverage. PHP line coverage from these checks is incidental; report it per suite without merging duplicate cycles or claiming SQL/JS/branch coverage. PCOV does not provide branch coverage. Final coverage gates remain Stage 4 targets.
- The server and test Laravel sessions use `ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`. Strict/zero-date migration compatibility and production version/modes remain a TODO; no production setting was inferred or changed.
- Browser tooling works with a sandboxed HTML fixture. Actual HMS journeys and private licensed assets remain deferred; no Font Awesome token/assets, real users/cards, production dumps or service credentials were introduced.
- Schema recreation is intentionally thorough and slower than rollback. Run serially initially; parallel database/queue/artifact isolation remains unverified. SQL clocks are not frozen by the PHP clock helper.
- The initial 2A verification blocker is resolved by the three-profile follow-up on the same managed `vfs` host, with no storage-driver change. Both cycles passed, retained reports reconciled, and cleanup completed. 2B owns the next SQL business suite. 2C/2D/2E/2F and the final workflow/consolidation specification remain planned; no new application hooks or behavior fixes are included.
