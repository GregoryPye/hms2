# Isolated test profiles

This runner prepares and runs three independent profiles using the local Docker daemon. It is the Stage 1 tooling plus Stage 2A regression foundation, not the finished tools business regression suite.

| Profile | Prepared runtime | Delivered checks |
| --- | --- | --- |
| `backend` | PHP/Composer, disposable MySQL, tiny BusyBox probe | Safety, PHP/Pest tooling, real schema/routines/readback, foundation unit/database suites |
| `frontend` | Small Node image, tiny probe | Safety, Vue/Jest DOM and unchanged tool-grant template smoke; no PHP/MySQL/Chromium |
| `browser` | Playwright/Chromium, tiny probe | Safety and sandboxed desktop/mobile browser tooling; actual HMS journeys remain deferred |

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

There is no need to prepare all profiles first. `prepare` defaults to backend and accepts only one profile; `pr-check` defaults to all. `smoke` uses the same profiles/safety checks without adding the PHP foundation business-support tests. `aggregate --output /tmp/hms-pr-check` independently reconciles `backend/report.json`, `frontend/report.json` and `browser/report.json`; only matching complete PR-check reports with all required checks can pass. An absent, failed, duplicate, stale, incomplete or smoke-only profile cannot satisfy the PR check.

A profile preparation evicts other *harness-owned* profile caches, so run each independent profile before preparing the next. The combined command also evicts the just-completed owned cache by default. `--retain-cache` keeps the final combined profile cache on hosts with space. `clean --profile backend|frontend|browser|all` removes only registered resources allocated by this harness; unrelated/pre-existing images are borrowed and preserved. Owned image references must still resolve to their recorded IDs; owned volumes must carry the expected profile label. Build cache removal is filtered to exact IDs allocated during this harness's build, never a global prune. Referenced parent build-cache entries remain registered and may require subsequent exact-ID cleanup passes. A lock prevents overlapping runner/preparation commands. Downloaded pinned SQL is a small shared preparation cache.

Reports contain per-profile results and sampled resource figures: total/used disk at start/end, peak used disk, minimum free space and observed container memory. Disk includes retained cache costs. Memory samples are observations, not exact instantaneous peaks; zero with no successful samples means unavailable observation, not zero usage; container memory/process limits remain enforced. Preparation resource reports live under `.cache/`; the combined report includes each preparation measurement. Artifacts and caches are ignored by Git. See the [2A report](../../docs/testing/stage2a-foundation-report.md) for measured results and limits.

## Isolation and preparation

Preparation copies only reviewed tracked product files and explicit test-file allowlists. It excludes `.env`/hidden files, config caches, runtime storage, credentials, symlinks and Git metadata. Product/tooling checksums and pinned revisions must match before execution. Backend source plus locked vendor dependencies are installed in a named volume; JS profiles have separate dependency/source volumes. Runtime mounts are read-only and validated by the controller, with test storage/cache/tmp overlaid as tmpfs. No host checkout, output directory or socket is mounted into tests.

The small PHP image contains only runtime tooling. Source edits do not create successive image layers: preparation replaces a filtered bundle and installs locked dependencies with Composer scripts/plugins disabled. JS uses independent lockfiles and `npm ci --ignore-scripts`; frontend does not install Playwright or private product assets. Image references are pinned in `images.json`. The preparation-only volume population helper has no network and only `CHOWN` capability; the separate non-root installer has public download access and a read-only CA bind, and never runs HMS. These are controller-owned preparation steps, not test processes.

Execution always uses `--pull=never` and explicit `env -i` environments. Each profile independently verifies production/credential/host-sentinel/cache rejection, no default route/external DNS/TCP/SMTP/MQTT, cross-bridge canary denial, child isolation and cleanup. The tiny canary is positively checked on its own isolated bridge. PHP and JS guarded entrypoints perform preflight before vendor loading. Mail/notification/broadcast/queue/bus/HTTP expectations are strict in the PHP foundation. Direct MQTT construction stays deferred; network denial remains the independent protection.

All test/control containers are non-root, read-only, capability-dropped, no-new-privileges and resource-limited, without host binds/published ports. Pure units/components/browser tooling have no network; DB tests use only their disposable isolated MySQL bridge. Backend no-network workers are removed before MySQL work; all backend services are removed before JS profiles. Related checks use fresh PHP/Node processes in a shared isolated worker; clean cycles recreate workers, networks and MySQL. PHP/Jest clocks/listeners/ORM state are tested/reset; PHPUnit reverses order and browser viewports reverse on cycle 2, while Jest uses a fixed randomized seed. Cleanup reconciles late creates on cancellation.

The temporary MySQL modes remain `ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` in the disposable server and Laravel sessions. TODO: confirm production version/modes and revisit strict timestamp migration compatibility. No production configuration, migration or pinned routine is changed by the split. No scheduler, general worker, production seeder or deployment script runs.

## Reports and scope

`pr-check --profile all` exits 0 only if all three delivered profiles complete both cycles with every required check passing. JUnit/Clover/Jest/browser JSON reports are size-bounded, named artifacts streamed before tmpfs disappears; raw base64 is stripped from ordinary logs. Full HMS browser journeys remain an explicit asset/workflow deferral and never count as passing tooling tests. Each profile report records its common product/tooling identity for cross-job aggregation.

The local control-plane tests use Python standard-library mocks/temp files, no Docker calls or HMS bootstrap:

```sh
python3 -m unittest discover -s tests/Environment -p test_runner.py -v
```

[Regression support](../../docs/testing/regression-harness.md) explains fixtures, transaction versus recreation resets, coverage and extension. The [scenario inventory](../../docs/testing/tools-regression-inventory.md) and [task](../../docs/tasks/%23TBD_tools_regression_test_harness.md) track business tests, defects and later hooks. No workflow, issue, commit or push is created by the runner.

## Browser profile attribution

`playwright-seccomp.json` follows Playwright v1.56.0's published profile with its Apache-2.0 notice in `PLAYWRIGHT-LICENSE.txt`. The documented namespace-local `chroot` allowance supports Chromium's user sandbox with outer capabilities dropped. `chromiumSandbox: true` remains mandatory; no privileged/host-network/`--no-sandbox` fallback exists. Node and BusyBox use pinned upstream images with their upstream notices (Node's licences and BusyBox GPL-2.0); no private assets or image binaries are copied into the repo.
