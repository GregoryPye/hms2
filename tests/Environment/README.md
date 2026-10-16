# Isolated test profiles

This runner prepares and runs three independent profiles using the local Docker daemon. It includes Stage 1 tooling, Stage 2A foundation, Stage 2B SQL, Stage 2C backend PHP and Stage 2D routed HTTP and Stage 2E frontend component cases. Stage 2F adds selected actual browser journeys using the approved public-only test bundle.

| Profile | Prepared runtime | Delivered checks |
| --- | --- | --- |
| `backend` | PHP/Composer, disposable MySQL, tiny BusyBox probe | Safety, PHP/Pest tooling, real schema/routines/readback, foundation unit/database suites four SQL business groups and five backend PHP groups |
| `frontend` | Small Node image, tiny probe | Safety, Vue/Jest smoke and 98 actual component cases with seven-SFC coverage; no PHP/MySQL/Chromium |
| `browser` | Chromium-only Playwright runtime, tiny probe, explicit PHP/MySQL prerequisite | Independent safety/tooling, real login/cookies/CSRF, visibility/settings, calendar lifecycle and native grant journeys at desktop/mobile viewports |

Requirements: Linux x86-64, Python 3 standard library and local Docker Engine supporting internal bridges with isolated gateway mode (tested with Docker 28.4). Runtime containers never receive Docker access. No host PHP/Node/MySQL, production config or service credentials are needed. Preparation needs public dependency/image access and a trusted CA bundle (`SSL_CERT_FILE` or `/etc/ssl/certs/ca-certificates.crt`); managed Docker proxies and TLS verification are preserved.

Independent preparation and execution, from the repository root:

```sh
python3 tests/Environment/run.py prepare --profile backend
python3 tests/Environment/run.py pr-check --profile backend --cycles 2 --output /tmp/hms-backend

python3 tests/Environment/run.py prepare --profile frontend
python3 tests/Environment/run.py pr-check --profile frontend --cycles 2 --output /tmp/hms-frontend

python3 tests/Environment/run.py prepare --profile browser
python3 tests/Environment/run.py pr-check --profile browser --cycles 2 --output /tmp/hms-browser
```

The bounded combined command stages preparation, two clean cycles, artifact retention and cleanup for each profile in sequence:

```sh
python3 tests/Environment/run.py pr-check --profile all --cycles 2 --output /tmp/hms-pr-check
```

There is no need to prepare all profiles first. `prepare` defaults to backend and accepts only one profile; `pr-check` defaults to all. `smoke` uses the same profiles/safety checks without adding PHP or frontend business tests. `aggregate --output /tmp/hms-pr-check` independently reconciles `backend/report.json`, `frontend/report.json` and `browser/report.json`; only matching complete PR-check reports with all required checks can pass. An absent, failed, duplicate, stale, incomplete or smoke-only profile cannot satisfy the PR check.

A profile preparation evicts other *harness-owned* profile caches, except browser preparation retains its explicit backend application prerequisite, so run each independent profile before preparing the next. The combined command also evicts the just-completed owned cache by default, including the browser's backend prerequisite at the end. Standalone browser preparation retains that prerequisite; use `clean --profile all` to remove both sets of owned caches. `--retain-cache` keeps the final combined profile cache on hosts with space. `clean --profile backend|frontend|browser|all` removes only registered resources allocated by this harness; unrelated/pre-existing images are borrowed and preserved. Owned image references must still resolve to their recorded IDs; owned volumes must carry the expected profile label. Build cache removal is filtered to exact IDs allocated during this harness's build, never a global prune. Referenced parent build-cache entries remain registered and may require subsequent exact-ID cleanup passes. A lock prevents overlapping runner/preparation commands. Downloaded pinned SQL is a small shared preparation cache.

Reports contain per-profile results and sampled resource figures: total/used disk at start/end, peak used disk, minimum free space and observed container memory. Disk includes retained cache costs. Memory samples are observations, not exact instantaneous peaks; zero with no successful samples means unavailable observation, not zero usage; container memory/process limits remain enforced. Preparation resource reports live under `.cache/`; the combined report includes each preparation measurement. Artifacts and caches are ignored by Git. See the [2A report](../../docs/testing/stage2a-foundation-report.md) for measured results and limits.

## Isolation and preparation

Preparation copies only reviewed tracked product files and explicit test-file allowlists. It excludes `.env`/hidden files, config caches, runtime storage, credentials, symlinks and Git metadata. Product/tooling checksums and pinned revisions must match before execution. Backend source plus locked vendor dependencies are installed in a named volume; JS profiles have separate dependency/source volumes. Runtime mounts are read-only and validated by the controller, with test storage/cache/tmp overlaid as tmpfs. No host checkout, output directory or socket is mounted into tests.

The small PHP image contains only runtime tooling. Source edits do not create successive image layers: preparation replaces a filtered bundle and installs locked dependencies with Composer scripts/plugins disabled. JS uses independent lockfiles and `npm ci --ignore-scripts`; frontend does not install Playwright or private product assets. Base image references are pinned in `images.json`; derived PHP/Chromium runtime identities include their recipe, pinned inputs and single-layer packaging recipe. The legacy full-browser pin remains historical and is not used by 2F. The preparation-only volume population helper has no network and only `CHOWN` capability; the separate non-root installer has public download access and a read-only CA bind, and never runs HMS. These are controller-owned preparation steps, not test processes.

Execution always uses `--pull=never` and explicit `env -i` environments. Each profile independently verifies production/credential/host-sentinel/cache rejection, no default route/external DNS/TCP/SMTP/MQTT, cross-bridge canary denial, child isolation and cleanup. The tiny canary is positively checked on its own isolated bridge. PHP and JS guarded entrypoints perform preflight before vendor loading. Mail/notification/broadcast/queue/bus/HTTP expectations are strict in the PHP foundation. Direct MQTT construction stays deferred; network denial remains the independent protection.

All test/control containers are non-root, read-only, capability-dropped, no-new-privileges and resource-limited, without host binds/published ports. Pure units/components/browser tooling have no network; DB tests use only their disposable isolated MySQL bridge. Backend no-network workers are removed before MySQL work; completed backend/frontend profile services are removed before browser integration starts its own isolated PHP/MySQL prerequisite. Related checks use fresh PHP/Node processes in a shared isolated worker; clean cycles recreate workers, networks and MySQL. PHP/Jest clocks/listeners/ORM state are tested/reset; PHPUnit reverses order and browser viewports reverse on cycle 2, while Jest uses a fixed randomized seed. Cleanup reconciles late creates on cancellation.

The temporary MySQL modes remain `ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` in the disposable server and Laravel sessions. TODO: confirm production version/modes and revisit strict timestamp migration compatibility. No production configuration, migration or pinned routine is changed by the split. No scheduler, general worker, production seeder or deployment script runs.

## Reports and scope

`pr-check --profile all` exits 0 only if all three delivered profiles complete both cycles with every required check passing. JUnit/Clover/Jest/Istanbul/browser JSON reports are size-bounded, named artifacts streamed before tmpfs disappears; raw base64 is stripped from ordinary logs. Actual HMS journeys are separate required browser checks; the tooling fixture cannot substitute for them. Full production visual assets and live broadcasts remain outside the selected functional scope. Each profile report records its common product/tooling identity for cross-job aggregation.

The local control-plane tests use Python standard-library mocks/temp files, no Docker calls or HMS bootstrap:

```sh
python3 -m unittest discover -s tests/Environment -p test_runner.py -v
```

[Regression support](../../docs/testing/regression-harness.md) explains fixtures, transaction versus recreation resets, coverage and extension. The [scenario inventory](../../docs/testing/tools-regression-inventory.md) and [task](../../docs/tasks/%23TBD_tools_regression_test_harness.md) track business tests, defects and later hooks. No workflow, issue, commit or push is created by the runner.

## Browser profile attribution

`playwright-seccomp.json` follows Playwright v1.56.0's published profile with its Apache-2.0 notice in `PLAYWRIGHT-LICENSE.txt`. The documented namespace-local `chroot` allowance supports Chromium's user sandbox with outer capabilities dropped. `chromiumSandbox: true` remains mandatory; no privileged/host-network/`--no-sandbox` fallback exists. Node and BusyBox use pinned upstream images with their upstream notices (Node's licences and BusyBox GPL-2.0); no private assets or image binaries are copied into the repo.

## SQL regression selection

Backend PR-check now includes `SqlStatusAccess`, `SqlInduction`, `SqlCharging` and `SqlReporting` alongside foundation checks. To run only SQL business cases plus the mandatory safety/database prerequisites:

```sh
python3 tests/Environment/run.py prepare --profile backend
python3 tests/Environment/run.py pr-check --profile backend --suite sql --cycles 2 --output /tmp/hms-sql
```

The preparation is shared with normal backend execution. SQL-only reports carry `suite: sql` and cannot satisfy a complete three-profile aggregate. Normal backend execution omits `--suite sql` and also reruns foundation checks. No frontend/browser image or service is needed. All cases recreate the disposable schema because real routines commit internally; expect this to take longer than pure unit tests.

Each PHP suite records leaf test/assertion/pass/failure/error/skip counts and individual nonpassing cases in JSON, with original JUnit/Clover artifacts retained. Failing business contracts remain nonzero, including recorded pre-existing defects; they are not converted into passing expected-failure tests. See the [2B report](../../docs/testing/stage2b-sql-report.md) for scenario scope and findings.

## Backend PHP regression selection

Default backend `pr-check` includes Stage 2C's `BackendUnit`, `BackendRepository`, `BackendLifecycleAudit`, `BackendMembershipRetention` and `BackendReportingWorkflow`, plus foundation/SQL checks. Pure rule tests run without a network before disposable MySQL starts. To iterate on just foundation and 2C with all applicable safety controls:

```sh
python3 tests/Environment/run.py prepare --profile backend
python3 tests/Environment/run.py pr-check --profile backend --suite backend --cycles 2 --output /tmp/hms-backend-php
```

Use `--suite backend-unit` for foundation unit checks and the 70 backend rule cases with container networking disabled and **no MySQL startup**. Use `--suite backend-integration` for database foundation and the four persistence/workflow groups without unit or SQL business suites. Both use the same prepared backend snapshot:

```sh
python3 tests/Environment/run.py pr-check --profile backend --suite backend-unit --cycles 2 --output /tmp/hms-backend-unit
python3 tests/Environment/run.py pr-check --profile backend --suite backend-integration --cycles 2 --output /tmp/hms-backend-integration
```

All partial selections are marked in their reports and cannot satisfy complete aggregation. JSON coverage summaries union executable statement lines across completed PHP reports, including failing tests; repeated cycles never inflate coverage. Both core and full fixed-allowlist scopes retain uncovered source files. Coverage establishes execution, not passing behavior. The default backend command remains the cumulative verification path; it retains unresolved SQL and PHP contract failures. The complete three-profile regression is recorded in the Stage 2G consolidation; a focused PHP selection does not replace it. PHP logs display deprecations and retain failed assertions; no expected-failure wrapper masks them. See the [2C report](../../docs/testing/stage2c-backend-report.md) for event/transport boundaries, coverage and findings.


## Routed HTTP regression selection

Stage 2D adds `HttpAuthentication`, `HttpBooking`, `HttpToolPages`, `HttpToolAccess` and `HttpMemberSearch` to default backend `pr-check`. Run them independently with all safety/database prerequisites and database foundation:

```sh
python3 tests/Environment/run.py prepare --profile backend
python3 tests/Environment/run.py pr-check --profile backend --suite http --cycles 2 --output /tmp/hms-http
```

This partial selection cannot satisfy complete three-profile aggregation. `--suite backend` continues to mean foundation plus 2C PHP groups; use the default selection for cumulative backend/SQL/HTTP verification. Tool page/CRUD and access/audit cases use separate PHP processes to stay inside the existing 128 MB PHP limit; container limits and safety gates are unchanged.

Requests execute the actual application kernel in-process, without a browser/server or external client. The request application boots after synthetic permissions exist so production ACL registration sees this case's definitions. Authentication helpers cover the broad matrix; local credential/session and real signed/revoked bearer cases exercise actual guards. Temporary RSA keys are generated only in process memory/config. Preparation derives `/app/version` from the snapshot commit for the real footer; a strict test-only Mix URL resolver replaces absent compiled-asset lookup. Product views/routes are untouched, scripts/assets are never fetched, and no licensed build or production keys are needed. Standard testing-mode CSRF behavior does not prove browser CSRF protection. See the [2D report](../../docs/testing/stage2d-http-report.md) for precise boundaries and findings.

## Frontend component regression

`pr-check --profile frontend` now runs the two tooling smoke cases separately from 98 business cases in `calendar.test.cjs`, `list.test.cjs`, `selection.test.cjs` and `forms.test.cjs`. `smoke --profile frontend` runs only the tooling cases. Both retain the full profile safety gates and two fresh cycles; cycle two shuffles cases using seed 42. No PHP, database, Chromium, production entrypoint or asset build is loaded.

The suite mounts all four actual Tools SFCs plus MemberSearch, MemberSelectTwo and SelectTwo. Test-only pinned public dependencies compile Vue scripts/templates; the test transform prevents inner/outer instrumentation from overwriting calendar coverage. FullCalendar, Axios, Echo, loading and jQuery plugins are explicit recording ports. Real Vue, jQuery DOM, moment/timezone and humanize-duration execute locally. Add tests to these files or extend the explicit copy/hash allowlist in `run.py`; do not load product app configuration or private assets.

Responses must be queued explicitly; unused expectations, unexpected transports and Vue runtime errors fail the case. Each case freezes time, resets DOM/recordings, destroys wrappers and restores clocks/spies. Cleanup-contract tests assert pending work before the harness clears residual timers. The FullCalendar port permits duplicate public IDs and removes individual event instances so it cannot hide duplicate insertions.

`frontend-components` is a required PR check distinct from `frontend-unit`. It emits named JUnit, Jest JSON, raw Istanbul `coverage-final.json` and `coverage-summary.json` artifacts. Per-cycle JSON records counts, passing expectations and coverage across the fixed seven-component scope. Passing expectations are not a total of all executed expectations. Missing, malformed, empty or differently scoped summary evidence blocks the check; reports are removed before each invocation to prevent stale reuse. Coverage counts compiled SFC script/render code, including failing tests and uncovered branches; it does not measure CSS, external plugin code, PHP/SQL or real browser integration.

Current output correctly exits 1: seven contract failures reproduce DEF-03/14/15/16. They remain ordinary failing tests, with no skips, expected-failure wrappers or product fixes. See the [2E report](../../docs/testing/stage2e-component-report.md) for exact evidence and limitations. Backend/browser/full aggregation were not rerun for this increment; the common tooling identity prevents combining earlier reports as current results.

## Integrated browser journeys (Stage 2F)

```sh
python3 tests/Environment/run.py prepare --profile browser
python3 tests/Environment/run.py pr-check --profile browser --cycles 2 --output /tmp/hms-2f-browser
```

Browser preparation includes the exact current guarded backend bundle and disposable MySQL image; a missing/stale/evicted application dependency invalidates preparation. It builds selected actual SFCs using the user-approved public-only test entrypoint. Real Vue, FullCalendar, Axios, Bootstrap Confirmation, Select2, Bootstrap CSS and repository-owned component/theme styles execute. No private icons/external fonts, production entrypoint, private registry, application source edit or product asset manifest is used. Echo subscriptions are recorded without starting Pusher or a websocket service; this does not prove live delivery. Only explicit current test files/product source are copied and hashed.

The full upstream multi-browser image exceeded this vfs host's integrated resource budget. The new recipe uses the pinned Node base and Playwright 1.56.0's supported Chromium headless-shell-only installer. Prepared PHP and Chromium filesystems are copied unchanged into one final Docker layer to reduce vfs parent-tree duplication; their effective runtime paths/environment are explicit. Chromium sandboxing, the established seccomp profile, non-root/capability/read-only restrictions, Docker driver and limits are retained. Public dependency/asset preparation uses the small Node image; the asset build has no network. Build-input release is limited to exact recorded owned references/cache IDs; runtime/source/MySQL and borrowed resources remain intact.

Each cycle first proves browser and PHP isolation/config rejection independently. PHP, browser and MySQL then use a new internal isolated bridge with no published ports. A test-owned PHP router handles actual kernel/routes/middleware and serves only prepared local assets/branding; there are no test HTTP fixture/reset endpoints. Real credential login uses tmpfs file sessions and one generated synthetic Passport keypair per cycle; the existing guarded array-session bootstrap remains unchanged and only this server overrides its session runtime. Cookies use actual wall time. Browser processes receive no DB credentials, signing keys or application volume.

Four named journeys run at desktop and mobile viewport sizes, resetting synthetic state and creating a fresh browser context/process between cases. Cycle two reverses both journey and viewport order. Calendar creation/movement/resizing/cancellation uses actual DOM pointer/plugin interactions; only date navigation uses the real calendar API. Mobile currently means the narrower responsive viewport with mouse interaction, not touch-device gesture coverage. Denial cases include actual CSRF, invalid login and permission failures. A separate PHP process verifies final booking/access/audit/event/financial state after every journey. It catches bootstrap/contract exceptions explicitly and exports a verified result only after assertions; the controller independently checks the semantic state. A deliberate empty-lifecycle negative must exit 1, preventing Laravel CLI exception rendering from masquerading as success. No general worker, scheduler, MQTT, payment/mail client or live remote service runs.

Each journey emits bounded JUnit and JSON with its identity/result; the controller rejects mismatched or missing evidence. It exports neither storage-state/cookies, request headers, CSRF/bearer material, raw HTML nor signing keys. State verification is a separate required check, so a successful DOM interaction alone cannot satisfy a persisted outcome. No GitHub workflow is enabled. See the Stage 2F delivery report for final results, resource evidence and any blockers.


## Consolidated first draft (Stage 2G)

The [current first-draft report](../../docs/testing/stage2g-draft-report.md) reconciles all three profiles and records common-baseline results, coverage, safety, repeatability and open findings. The [enabling/gap plan](../../docs/testing/stage3-isolation-and-coverage-plan.md) separates the proposed MQTT seam from local websocket and non-hook coverage work. The [future GitHub PR specification](../../docs/testing/github-pr-workflow-spec.md) defines profile jobs, artifacts and aggregation without installing or activating a workflow. The first draft remains useful for review while ordinary product/legacy failures keep its gate red; no final coverage acceptance or defect waiver is implied.
