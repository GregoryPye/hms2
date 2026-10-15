# Stage 1: isolated environment smoke checks

This harness prepares and exercises development/test infrastructure against unchanged HMS2 application code. It is not the tools regression suite and does not claim tools coverage. Product defects and tests needing new injection hooks belong to the later stages in the task document.

## Requirements and commands

- Linux x86-64, Python 3 with its standard library, Git, and a local Docker Engine supporting internal bridges with `gateway_mode_ipv4=isolated` (tested with Docker 28.4).
- A Docker daemon accessible through `/var/run/docker.sock`. The outer runner needs daemon access; the test containers never receive that socket.
- Internet access only for preparation: the pinned public container images, locked Composer packages, public frontend test packages, and the public database submodule archive. No application service credentials are needed or accepted.
- A trusted CA bundle for preparation, selected by `SSL_CERT_FILE` or `/etc/ssl/certs/ca-certificates.crt`. Managed cloud Docker proxy settings are preserved for downloads; tests have no proxy access.
- Several GB of memory and sufficient Docker storage. A `vfs` Docker storage driver can use over 30 GB during initial builds and test runs; overlay-backed Docker is substantially more economical. The runner does not prune unrelated images, volumes, or caches.

Run from the HMS2 checkout:

```sh
python3 tests/Environment/run.py prepare
python3 tests/Environment/run.py smoke --cycles 2
```

Preparation builds the PHP image, disables Composer plugins/scripts, generates its autoloader without running application commands, and installs only the standalone public frontend tooling with npm lifecycle scripts disabled. It downloads SQL from the exact database gitlink revision without initializing or changing the working-tree submodule. The application's private npm asset dependency is not installed.

Preparation caches a manifest and dependency-build logs under `.cache/`, a labelled frontend dependency volume, and local Docker images. Image references and the standalone frontend package lock are checked into this directory. Record the resolved runtime versions from smoke output as well as the image IDs.

Smoke execution has no dependency-install step. It refuses fewer than two cycles and recreates MySQL and networks for each cycle. It checks prepared product-source hashes; run `prepare` again after changing the source or harness. Preparation is required on a new machine because `.cache/` is not committed.

The disposable MySQL server and test-only Laravel connections temporarily use `ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`, without strict/zero-date rejection, to allow historical migrations to run. TODO: confirm production MySQL/MariaDB version and server/session SQL modes, investigate legacy timestamp handling, and revisit strict-mode coverage. Passing these checks does not establish strict-mode compatibility. Production configuration is unchanged.

By default reports go to `tests/Environment/artifacts/`, which is ignored by Git. An explicit output directory is also supported:

```sh
python3 tests/Environment/run.py smoke --cycles 2 --output /tmp/hms-stage1-results
```

The command exits nonzero if a required smoke check is blocked or fails. A separately reported deferred full-asset/browser-journey check is not counted as passing. Read `report.json` and each named check's log; do not interpret successful unit tooling as readiness of the database or browser layers.

## What is isolated

- The PHP image contains a filtered copy of named, tracked source files and test-owned harness files. Working-tree `.env` files, cached bootstrap configuration, runtime storage, credential files, symlinks, and Git metadata are not copied. Permissions are normalized so the unprivileged test user can read source without being able to write it.
- Every test process starts with an explicit environment via `env -i`. No AWS/cloud, SSH, GitHub, mail, payment, private registry, or other production credentials are passed. The sample application key and MySQL credentials in the runner are synthetic, local test values.
- Unit/tooling containers have `--network none`. Database-backed containers share a unique internal bridge with isolated gateway mode: no default route, host bridge address, or published ports. A second, separate internal network holds a controlled TCP listener for positive/negative isolation checks.
- Test containers run as non-root with a read-only root filesystem, all outer capabilities dropped, no-new-privileges, and bounded memory/process counts. Writable storage/cache/temp paths are disposable tmpfs mounts. There are no host filesystem binds. Frontend dependencies are the single explicitly named, read-only Docker-volume exception.
- MySQL has no published port or persistent data mount. Its files live on tmpfs. Its application account has privileges only on `hms_smoke`; the runner checks that system-user-table access is denied. Only the initialization container receives the separate synthetic root password.
- The test-owned Laravel bootstrap supplies an explicit null queue connection through `afterBootstrapping(LoadConfiguration::class, ...)`. Broadcasts use the local log driver, mail/cache/session are in-memory, and password storage uses Doctrine. Effective configuration is checked before any selected application work. These are test-only settings, not production config changes.
- Runtime preflight rejects production mode, unexpected database settings, credential sentinels and cached config. Network checks cover TCP/SMTP/MQTT destinations, external DNS resolution, a known reachable listener on another bridge, and a child process. Browser checks include raw TCP denial and actual browser HTTP/websocket denial without a request-interception fake masking the network result.
- No scheduler, Horizon, general queue worker, production seeder or deployment script is run. No production dump is loaded. The only persisted fixture is a synthetic tool in the disposable database.
- Each cycle removes its containers, anonymous volumes and networks, including reconciling late container creation after cancellation. It retains only the preparation cache/dependency volume and sanitized reports. Hard host shutdown or forcibly killing the runner with SIGKILL cannot run cleanup; the runner's unique `hms-smoke-...` names identify any resources to remove after such an interruption.

The dependency-preparation container is a distinct exception: it has network access, a public CA-bundle bind, and temporary write access to a labelled dependency volume. It receives no HMS credentials/config and runs only the locked public package installer with scripts disabled. It does not run HMS.

## Checks and their meaning

| Check | Evidence |
| --- | --- |
| Isolation controls | Positive listener control; no-network and internal-network rejection; config/credential/cache rejection before bootstrap; container configuration assertions; child-process denial. |
| PHP tooling | Pinned PHP, required extensions and coverage driver, locked framework/Pest/PHPUnit versions, test discovery and the existing unit example. This is an environment check, not meaningful regression coverage. |
| MySQL | Exact server version, SQL mode/collation, schema-scoped account, and rejection of system-table access. Initial target: MySQL 8.4.6, pending confirmation of deployment compatibility. |
| Application | Unmodified Laravel console bootstrap, configuration validation, route registration, Doctrine resolution, and a real null queue connection. No external application transport is intentionally exercised. |
| Laravel/Doctrine schema | Original checked-in migrations on a clean database. Failures remain visible; the harness does not rewrite migrations or substitute a schema dump. |
| Fixture | Persist a synthetic tool via the real Doctrine repository, clear the identity map, read it through Doctrine, and read the same row through Laravel's database connection. |
| SQL loader | Original pinned functions/procedures/views, with mysql-client `DELIMITER` directives removed for PDO execution. Successful completion also calls the tool-status procedure. No original SQL file is changed. |
| Frontend unit tooling | Vue 2 DOM mounting, interaction and cleanup through Jest/Test Utils; compilation of the unchanged HMS tool-grant template. |
| Browser tooling | Sandboxed Chromium, a mobile-sized DOM interaction, HTTP/websocket egress rejection. Uses an HTML fixture, not a complete HMS browser journey. |
| Cleanup/restart | A second fresh cycle, plus explicit checks for remaining cycle containers. |

Schema failure blocks SQL-dependent layers. The fixture diagnostic may still be attempted if application/bootstrap and MySQL work, to show whether an existing table supports readback; it does not turn a partial migration run into a passing schema check.

## Browser sandbox profile and attribution

`playwright-seccomp.json` is based on the published [Playwright v1.56.0 Docker seccomp profile](https://github.com/microsoft/playwright/blob/v1.56.0/utils/docker/seccomp_profile.json), under Apache-2.0; see `PLAYWRIGHT-LICENSE.txt`. It allows Chromium's user-namespace sandbox. One additional `chroot` syscall rule allows the sandbox to chroot within its own user namespace when all outer container capabilities have been dropped. It grants no outer capabilities and does not disable seccomp or Chromium sandboxing.

Browser launch remains `chromiumSandbox: true`; there is no fallback to `--no-sandbox`, privileged mode, host networking, or extra capabilities. A host that forbids user namespaces will report a browser blocker. Network isolation and credential exclusion still apply independently of Chromium's sandbox.

## Boundaries and future PR integration

The full application asset build and actual HMS browser journeys remain deferred because Font Awesome Pro requires a separately agreed licensed, credential-free asset strategy. The smoke checks do not fetch those assets or use a private registry token.

This directory supplies local smoke commands only. No workflow, issue, branch protection, commit or push is created. A future GitHub PR workflow can prepare dependencies, then invoke the same smoke command without application secrets and retain sanitized results. Stage 2 will define the broader regression-suite entrypoint and coverage/JUnit reporting.

See [the task document](../../docs/tasks/%23TBD_tools_regression_test_harness.md) for the delivery boundaries and later decisions.
