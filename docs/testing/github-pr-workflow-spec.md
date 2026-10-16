# Future GitHub PR regression workflow

This is the Stage 2G specification, not an installed workflow. No `.github/workflows` file or branch-protection setting is added. The [first-draft report](stage2g-draft-report.md) records local execution; the [runner guide](../../tests/Environment/README.md) defines executable commands. Activation and product defect fixes require later work.

## Trigger and trust boundary

Use `pull_request` against the repository's default branch, plus optional `workflow_dispatch` for diagnostics. Do not use `pull_request_target`, privileged reusable workflows or persistent self-hosted runners to execute PR code. Set workflow permissions to `contents: read`, with no environment secrets, cloud identities, write token, private registry credentials or production service configuration. Checkout the same PR merge revision in every job, with `persist-credentials: false`; record that SHA. Use a concurrency group per PR and cancel superseded runs. Cancellation must retain the runner's cleanup trap; disposable runner destruction is an additional boundary.

Use fresh Linux x86-64 hosted runners with Python 3, Git, a healthy local Docker daemon, public HTTPS access and the normal verified system CA bundle. Pin checkout/upload/download actions to reviewed full commit SHAs before activation. Select and verify a supported Ubuntu runner release; do not change the Docker storage driver. Do not expose the Docker socket, host checkout, token, credential files or output directory to test containers. The outer controller needs the socket to provision isolated resources; it runs untrusted PR code, so its host must itself be disposable and credential-free apart from the read-only checkout token.

Do not run the product Composer/npm setup or production scripts. The runner downloads the exact recorded SQL revision; no recursive submodule setup, database dump or private assets are needed. Only preparation may download pinned public dependencies/images. Preserve configured trusted proxies/CA for preparation. Runtime retains the runner's `env -i`, readonly filtered volumes, private/no-network profiles, effective config checks, synthetic credentials, sandbox and denial probes. Do not supply broad job environment values to containers.

## Jobs and commands

Prefer three parallel independent profile jobs plus a final aggregation job. Set matrix `fail-fast: false`; a red backend must not cancel frontend or browser evidence. An initial timeout budget is 60 minutes for backend, 30 for frontend and 60 for browser, to be adjusted from CI measurements. The underlying runner retains bounded individual commands/probes; do not replace timeouts with unbounded waits. There are no GitHub `services` containers: the harness provisions its own restricted MySQL and networks.

Every profile job performs:

1. Checkout the identical revision and run `python3 -m unittest discover -s tests/Environment -p test_runner.py -v`.
2. Record nonsecret OS/architecture, Docker version/storage driver and free disk. Budget at least 12 GiB free above checkout/borrowed images initially, then measure this runner. The local browser observation is not a universal capacity guarantee. Keep the existing enforced per-container limits; fail with diagnostics if capacity is insufficient.
3. Run `python3 tests/Environment/run.py prepare --profile PROFILE`.
4. Run `python3 tests/Environment/run.py pr-check --profile PROFILE --cycles 2 --output /tmp/hms-ci/PROFILE`, using respectively `backend`, `frontend`, `browser`, with the default full suite. A partial `--suite` or a smoke run cannot satisfy the PR gate. Leave nonzero statuses nonzero; no expected-failure wrapper or `continue-on-error` on this step.
5. Upload the whole `/tmp/hms-ci/PROFILE` report directory under a unique artifact name `hms-regression-PROFILE`, with `if: always()`, missing files treated as an upload failure, and an initial 14-day retention. Retain `report.json`, bounded logs, JUnit, PHP Clover, component Jest/Istanbul and browser JSON/state evidence. Do not upload `.cache`, dependency/source volumes, tmpfs, cookies, profiles, keys, raw HTML, credentials or preparation environment dumps. Review new artifact types before adding them.
6. In an always-run cleanup step, run `python3 tests/Environment/run.py clean --profile all`; preserve a failing cleanup as failure. Browser owns a backend application dependency too. If a killed runner prevents cleanup, destruction of its disposable VM handles remaining resources; never reuse that VM for another PR.

For a simpler single-job alternative, run the already exercised command:

```sh
python3 tests/Environment/run.py pr-check --profile all --cycles 2 --output /tmp/hms-ci
```

It prepares/runs/cleans profiles sequentially and produces the same directory layout. It continues to other profiles after recorded business failures. Upload artifacts even when it exits 1. The matrix is an execution/resource choice, not a change in test ownership or acceptance.

## Final aggregation and required status

The aggregation job uses `if: always()` and depends on all three profile jobs. Checkout the same revision, then download each artifact explicitly to `/tmp/hms-ci/backend`, `/tmp/hms-ci/frontend`, `/tmp/hms-ci/browser`, preserving each profile's internal report/artifact tree. Missing downloads must fail; do not ignore them. Run:

```sh
python3 tests/Environment/run.py aggregate --output /tmp/hms-ci
```

Upload the resulting combined `report.json` even on failure. Also require successful prerequisite/profile jobs so controller-test, upload or cleanup failures cannot be hidden by passing test reports. Give the final job a stable proposed check name, `tools-regression`, suitable for later branch protection. Do not install branch protection during this task or mark the check mandatory while the known red baseline is unresolved.

Aggregation requires exactly one full PR-check report per profile, matching product baseline/tooling digest, at least two completed cycles, passing outcomes and all required checks in every cycle. A missing/stale/duplicate/partial/smoke-only report cannot pass. It does not waive known failures or enforce future percentage targets: Stage 4 must add agreed numerical gates and any newly required scenario checks. Host controller tests exercise the aggregation rejection paths. Missing/corrupt report files also cause nonzero command failure rather than a green result.

## Failure triage and later activation

Read the aggregate, then the failing profile/check log and its leaf results. Distinguish an ordinary failed contract, SQL error, safety/prerequisite failure, incomplete execution and missing artifact. Current expected observation is a red aggregate from documented product/legacy findings, with passing browser journeys; this is not an approved failure allowance. Never remove a required check, relax isolation/SQL modes, install private assets, or narrow coverage to obtain green.

Before activation, select/review action SHAs and runner image, perform one real credential-free PR run including a fork PR, verify artifact paths and read-only permissions, test cancellation/cleanup and resource budgets, and decide existing defect/compatibility dispositions. Compare the actual CI reports with the local common-baseline evidence. No such GitHub execution or activation is claimed by this specification. Cache acceleration can follow separately using immutable lockfile/tooling keys and no untrusted shared mutable caches; start without cross-run caches.
