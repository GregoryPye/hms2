# Three-profile execution verification

Date: 2026-10-15. Product baseline: `f08bab4726307c8a75bbcb5a07c10b0bd9a51a45`, SQL `12ba6da237b6a97eb92e7efc53bf81c34844bfa8`. The split changes test infrastructure and documentation only. Application/configuration/routes/migrations/mappings and product dependency manifests remain unchanged. The managed Docker 28.4 daemon remains on `vfs`; no daemon replacement, storage-driver change or privileged test container was used.

## Implemented boundaries

| Profile | Runtime/dependencies | Delivered scope |
| --- | --- | --- |
| Backend | PHP 8.4.13, locked Composer packages, MySQL 8.4.6, BusyBox probe | PHP/Pest discovery/example, actual migrations/SQL loader/readback, FoundationUnit and FoundationDatabase, independent safety gates |
| Frontend components | Node 22.20.0 slim, Jest 29.7/Vue 2.7 tooling, BusyBox probe | DOM mount/click/cleanup and unchanged HMS tool-grant template compilation, independent safety gates; no Playwright/PHP/MySQL |
| Browser/integration | Playwright 1.56.0, bundled Chromium 141, BusyBox probe | Sandboxed desktop/mobile tooling and actual HTTP/websocket denial, independent safety gates; real HMS journeys remain deferred |

Profile-specific preparation copies filtered source into named source/dependency volumes, installs locked public dependencies with scripts/plugins disabled, and mounts the resulting bundle read-only during tests. The PHP image holds runtime tooling rather than successive source snapshots. Backend no-network work is removed before DB work; all live services are removed before the next profile. New processes isolate PHP/Node globals inside reusable workers, and fresh workers/bridges/MySQL instances bound each repeat cycle.

Image references and separate frontend/browser locks are checked in. Node and BusyBox use pinned public images; their upstream licence notices remain with those distributions. No private fonts, registry token, production data or new application hooks are introduced.

## Commands

Independent profiles were each prepared and run with:

```sh
python3 tests/Environment/run.py prepare --profile backend
python3 tests/Environment/run.py pr-check --profile backend --cycles 2 --output /tmp/hms-split-independent/backend
```

The same commands were exercised with `frontend` and `browser` and corresponding output directories. Their reports successfully reconciled with:

```sh
python3 tests/Environment/run.py aggregate --output /tmp/hms-split-independent
```

The cumulative command stages preparation, two clean/reordered cycles and owned-cache cleanup separately for each profile:

```sh
HMS_HOST_SENTINEL=host-only-sentinel STRIPE_SECRET=host-only-sentinel \
  python3 tests/Environment/run.py pr-check --profile all --cycles 2 --output /tmp/hms-split-final
```

These outer values are deliberate synthetic inheritance sentinels, not service credentials. Passing runtime preflight/child checks demonstrates that they were not inherited into positive test processes. The ordinary command needs neither variable set.

The final cumulative run exited **0**, with **80 required checks passed**, no failed/blocked checks, and two explicit actual-HMS-browser journey deferrals. Every profile completed both cycles. Generated JSON/JUnit/Clover/Jest/browser evidence stays in ignored output directories; this maintained report and the runner instructions preserve reproduction and interpretation.

## Independently measured resources

The independent runs passed two fresh cycles each. Approximate filesystem figures below are sampled every 0.25 seconds and include retained/prepared resources in the baseline; incremental runtime peaks should not be added together across sequential profiles.

| Profile | Required checks passed | Incremental runtime disk peak | Minimum free filesystem space |
| --- | --- | --- | --- |
| Backend | 40 | 2.74 GiB | 3.67 GiB |
| Frontend | 20 | 0.46 GiB | 15.09 GiB |
| Browser tooling | 20; two actual-HMS-journey deferrals | 5.13 GiB | 7.84 GiB |

These figures are observations on the 32 GB cloud filesystem, not a promise that future full HMS integration will need the same resources. Container memory/process limits remain enforced; per-profile reports include observed aggregate memory samples, not exact instantaneous RSS peaks. Backend and browser application services never overlap merely for orchestration.

## Safety, ordering and failure accounting

Each profile independently passes a positive tiny canary on its own isolated bridge and rejects access from another bridge/no-network worker. Runtime preflight precedes vendor loading and rejects production settings, unexpected DB configuration, credential and host sentinels, and cached configuration. TCP/SMTP/MQTT, child-process and known-public-domain DNS resolution checks run before selected product work. Browser checks use the actual network boundary, without request interception disguising denial. Chromium's sandbox stays enabled.

PHPUnit reverses order on cycle 2; Jest's fixed-seed randomized cycle was observed to reverse the two tooling cases, and browser viewport order reverses. The six PHP foundation tests make 45 assertions per cycle, including clock restoration, both ORM reset paths and strict transport expectations. Per-suite PHP line coverage remains incidental (the 47-file scope stays visible); this is not high tools business coverage.

Four Python control-plane tests pass with no Docker/network/app work: complete-report quorum versus failures/missing/duplicate/stale/incomplete/smoke-only/omitted checks, artifact traversal/duplicate rejection, cleanup retarget protection and checksum mismatch rejection before Docker. Positive real-report aggregation passed. Four deliberately corrupted copies (failed check, missing profile, stale tooling checksum and omitted required check) each returned exit 1 with a blocked aggregate; the original reports were preserved.

A controlled SIGTERM during backend worker startup returned exit 130 and a `cancelled` report, with no remaining test containers. Both completed-cycle and cancellation evidence are retained separately; cancelled/partial work is never counted as a successful repeat.

## Cache accounting and remaining scope

The first combined run already passed all 80 required checks, with two explicit real-HMS-browser deferrals. Its cache review identified parent-image imports that occurred before the original build-only cache snapshot. Ownership tracking now starts before backend image preparation, includes those imports, uses exact-ID `--all` pruning, and retains ownership when a referenced parent cannot yet be reclaimed. This was followed by a fresh full combined run. Cache cleanup never uses a blanket builder/image/volume prune, and borrowed resources or retargeted image references are preserved.

Some referenced BuildKit parent entries can need later cleanup passes. They remain registered and included in actual disk figures, rather than being silently forgotten. Runtime source edits replace bundles instead of accumulating image layers. Final settling cleanup reclaimed all registered images, volumes and BuildKit entries in six bounded passes. The ownership ledger is empty; no test containers or private networks remain. The filesystem then had approximately 17 GiB free.

Future 2B/2C/2D business suites, 2E component coverage, actual 2F HMS integration journeys, transport hooks and the 2G workflow specification remain separate deliveries. Licensed complete assets and strict-mode/production database compatibility remain explicit TODOs; this split does not waive either requirement. A future actual application-plus-browser profile still needs its own measured capacity evidence.

## Final cumulative measurements

| Profile | Completed cycles / required checks passed | Incremental runtime disk peak | Minimum free space |
| --- | --- | --- | --- |
| Backend | 2 / 40 | 2.74 GiB | 3.67 GiB |
| Frontend | 2 / 20 | 0.46 GiB | 10.58 GiB |
| Browser tooling | 2 / 20; two journey deferrals | 5.13 GiB | 3.91 GiB |

Across preparation and sequential execution, sampled filesystem usage peaked at **26.16 GiB**, leaving a minimum **3.67 GiB free** on the 32 GB filesystem. Backend's highest sampled aggregate container memory was approximately 810 MiB. Frontend/browser had no successful memory sample; their reported zero is missing observation, **not zero memory use**. Enforced per-container limits still apply.

Final generated evidence: `/tmp/hms-split-final/report.json` and its profile logs/JUnit/Clover/Jest/browser artifacts. All emitted JUnit files parsed with zero failures/errors. Independent reports are under `/tmp/hms-split-independent`; negative aggregation results under `/tmp/hms-split-negative/results.json`; cancellation evidence under `/tmp/hms-split-cancelled/report.json`; final cleanup accounting under `/tmp/hms-split-cache-cleanup.json`. These temporary paths are session evidence, not repository dependencies. Reproduction commands above and maintained summaries are the enduring record.

The delivered 2A foundation and three-profile resource follow-up are verified complete. Later business coverage and actual HMS integration remain planned. No commit, push or GitHub workflow activation was performed.
