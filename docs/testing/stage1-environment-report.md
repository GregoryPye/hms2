# Stage 1 environment smoke report

Date: 2026-10-15. Stage 1's isolated harness was initially exercised against unchanged application code. The user subsequently authorized quoting one historical migration identifier, resolving that first error; a later strict-date migration error then blocked database-backed suite readiness. The user subsequently authorized a temporary test-only SQL-mode relaxation; follow-up evidence is recorded below. The final relaxed-mode run passes all required smoke checks, with full-asset checks still deferred; it does not establish strict-mode compatibility. Stage 2 regression-test generation has not started.

## Reproduction

```sh
python3 tests/Environment/run.py prepare
python3 tests/Environment/run.py smoke --cycles 2 --output /tmp/hms-stage1-final
```

The initial strict-mode smoke command exited **1** because required database checks were blocked. It completes independent checks and removes the cycle's disposable resources. A nonzero result is intentional; the harness does not hide the database blocker or weaken its safety requirements.

See [the runner instructions](../../tests/Environment/README.md), [pinned images](../../tests/Environment/images.json), and [the task](../tasks/%23TBD_tools_regression_test_harness.md).

## Baseline and versions

| Component | Observed version or revision |
| --- | --- |
| HMS2 | `227bdd7f66e9b26da7871f036dbe4df0ec736639`, branch `tools-test-harness` |
| Database SQL submodule | `12ba6da237b6a97eb92e7efc53bf81c34844bfa8` |
| Docker Engine | 28.4.0, Linux x86-64, `vfs` storage driver |
| PHP / Composer | 8.4.13 / pinned Composer 2.8.12 image |
| Laravel / Pest / PHPUnit | 11.47.0 / 2.36.0 / 10.5.36 |
| Redis extension / PCOV | 6.2.0 / 1.0.12 |
| MySQL | 8.4.6; database collation `utf8mb4_0900_ai_ci` |
| Node / Vue | 22.20.0 / 2.7.16 |
| Jest / Playwright / Chromium | pinned Jest 29.7.0 / Playwright 1.56.0 / Chromium 141.0.7390.37 |

Initial MySQL SQL mode (before the authorized temporary relaxation):

```text
ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
```

The production database version/collation has not been verified. This is the task's provisional MySQL target, not evidence that the current production database uses MySQL 8.4.

## Initial smoke results before the identifier fix

| Layer | Result and meaning |
| --- | --- |
| Source preparation | Only allowlisted source and harness files copied; no `.env`, bootstrap config cache, runtime storage, credential directories or Git metadata. Product and harness checksums recorded. |
| Isolation | Positive TCP listener control works on its own network; unit and private-network tests cannot reach it across the boundary. No default route; documentation-address TCP/SMTP/MQTT and DNS resolution checks reject access. Child-process denial passes. |
| Configuration rejection | Production mode, unexpected database host, synthetic credential and injected cached Laravel config rejected before vendor/application bootstrap. |
| Container restrictions | Non-root, read-only source/rootfs, dropped capabilities, no-new-privileges, no host binds or published ports. Test dependencies are an explicit read-only volume; test storage is tmpfs. |
| PHP tooling | Extensions and locked tools available. Both existing tests discovered; the existing unit example executes successfully. This does not establish business regression coverage. |
| MySQL service | Fresh isolated server ready; application account restricted to the disposable schema and denied access to `mysql.user`. |
| Application bootstrap | Pass: 364 routes registered, real Doctrine EntityManager resolved, effective test configuration verified, real Laravel NullQueue resolved through test-only configuration. |
| Laravel migrations | Pass on the disposable database. |
| Doctrine migrations | Blocked by the unquoted `generated` identifier in the original invoices migration. |
| Fixture readback | Blocked: the partial migration chain has not added `tools.display_name`, so the original Tool repository cannot persist the current mapped entity. No alternate schema or application patch was substituted. |
| Original SQL routines/views | Not attempted as a readiness check because full schema prerequisites failed. The test-owned loader is present but its end-to-end SQL loading/readback remains unverified. |
| Vue/Jest tooling | Pass: Vue 2 DOM mounting/interaction/cleanup and compilation of the unchanged HMS tool-grant template. |
| Sandboxed browser tooling | Pass: mobile-sized HTML interaction and actual browser HTTP/websocket rejection. Chromium sandbox remains enabled. This is a tooling fixture, not an HMS browser journey. |
| Full application assets | Deferred: no Font Awesome Pro package/token supplied. No private registry credential used. |

The smoke run completed **two independent clean startups**, with **32 passing checks, six blocked checks, and two deferred checks**. Each cycle reports the same three database-dependent blockers (schema, fixture, and routine-loader readiness); the full asset build is separately deferred. Both cycles' cleanup checks pass. Machine-readable per-cycle results and logs are in `/tmp/hms-stage1-final/report.json` and adjacent files; these runtime artifacts are not committed.

Separate negative checks also corrupted the cached expected source/harness hashes with synthetic values and confirmed the runner refused execution before starting containers. The original preparation manifest was restored afterward.

A controlled SIGTERM cancellation immediately after the positive listener control also passed: exit status `130`, report outcome `cancelled`, zero completed cycles, and no new containers or networks left behind. The partial run was not labelled successful. Its evidence is in `/tmp/hms-stage1-cancellation-check.json` and `/tmp/hms-stage1-cancelled/report.json`.

## Database blocker

The original [invoices migration](../../database/migrations_doctrine/Version20190111040418_add_snackspace_invoice_table.php), line 17, includes:

```sql
CREATE TABLE invoices (..., generated DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, ...)
```

MySQL 8.4.6 rejects this with SQLSTATE `42000` / error `1064`. A read-only query of the disposable server's `information_schema.KEYWORDS` confirmed `GENERATED` has `RESERVED = 1`.

The chain stops before later tool migrations. A separate fixture diagnostic then fails with SQLSTATE `42S22` / error `1054`, `Unknown column 'display_name' in 'field list'`. This is a consequence of the partial schema, not evidence that Doctrine or Laravel cannot connect.

No migration, mapping, application source, production configuration, dependency manifest, or pinned SQL source was changed to bypass either failure. A separate decision is needed before claiming MySQL-backed tools regression readiness; the unchanged-code first draft can still use the independently working layers.

## Safety and scope reconciliation

- Application install scripts/plugins were disabled during dependency preparation. Tests ran without external network access, production credentials, host credentials or a deployed HMS instance.
- All application/database data was synthetic and disposable. No scheduler, general queue worker, deployment script, production seeder, email, payment, hardware or external notification workflow was intentionally run.
- Browser sandbox startup initially failed under Docker's default seccomp restrictions. The checked-in profile is based on Playwright's published profile, with an explicit namespace-local `chroot` allowance needed when outer capabilities are dropped. Chromium sandboxing stayed enabled; no privileged mode, host networking, added capabilities or `--no-sandbox` fallback was used.
- Harness-only trial defects (source permissions, a container-name collision, queue configuration and cancellation cleanup reconciliation) were corrected before the reported validation run. They were not product fixes.
- The MIT project licence remains unchanged. The copied Playwright seccomp profile's Apache-2.0 licence and provenance are included; no private font assets were downloaded. No user-facing terms change is proposed.
- GitHub workflow creation, issue creation, commits and pushes remain deferred. The task file stays in `docs/tasks`.

## Authorized identifier-quoting follow-up

The user authorized quoting `generated` in the invoices migration and adding an explanatory comment. The only application-source change is that identifier quoting and comment; the column name, type, default, foreign keys, and rollback behavior are unchanged. At that stage, no SQL mode or other migration was modified.

The cached test environment was rebuilt from the updated source and the isolated smoke command rerun with output under `/tmp/hms-generated-quoted`. Both clean cycles completed: 32 checks passed, six remained blocked by the later migration/incomplete schema, and two full-asset checks remained deferred. The command correctly exited 1 with outcome `blocked`; cleanup passed in both cycles, leaving no smoke containers or networks. The invoices migration now succeeds. Migration metadata confirms the chain subsequently reaches `Version20200514131421_alter_rooms_add_zone`, well beyond the previous failure.

The next migration, [alter buildings access state and occupancy](../../database/migrations_doctrine/Version20200515143749_alter_buildings_add_access_state_and_occupancy.php), adds `created_at` and `updated_at` as non-null datetimes to a populated table without a backfill/default. Under the recorded strict SQL mode, this fails with SQLSTATE `22007` / error `1292`, `Incorrect datetime value: '0000-00-00 00:00:00' for column 'created_at' at row 1`.

In that strict-mode run, fixture readback remained blocked by the incomplete chain, reporting missing `tools.hidden` rather than `tools.display_name`; full routines/views readiness was unverified. The blocker was recorded before the subsequent SQL-mode authorization below.

## Authorized temporary SQL-mode relaxation

The user authorized temporarily relaxing strict SQL mode in the disposable test environment, with TODO comments to investigate compatibility later. The runner now starts MySQL with `ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`: strict and zero-date rejection modes are omitted. The test-only bootstrap applies the same explicit modes to Laravel sessions, which otherwise re-enable strict mode independently. No production configuration or timestamp migration was changed.

TODO comments in `run.py` and `smoke.php`, plus the task's remaining questions, require confirming production MySQL/MariaDB version and server/session modes, investigating legacy timestamp handling, and revisiting strict-mode coverage. The relaxed run cannot establish strict-mode compatibility or prove production settings.

The first attempt (`/tmp/hms-nonstrict`) passed migrations, fixture readback, all 62 procedure files and 20 view files, and the tool-status procedure. It stopped before completing the frontend checks after Docker failed to create the frontend container while only 1.5 GB remained, consistent with insufficient space for the browser/tooling image under `vfs`; its outcome was correctly `incomplete`. Obsolete harness images/build cache were removed, freeing about 8 GB, and a fresh two-cycle run was started under `/tmp/hms-nonstrict-final`.

Final verification (`/tmp/hms-nonstrict-final/report.json`): both clean cycles completed, with **38 passing checks, zero blocked checks, and two deferred full-asset checks**. The command exited **0**, with outcome `pass`. Both cycles verified the relaxed SQL mode, completed Laravel/Doctrine migrations, persisted/read the synthetic tool, loaded 62 procedure files and 20 view files, called the tool-status procedure, passed frontend/browser tooling and isolation checks, and passed cleanup. No smoke containers or networks remained. Full HMS browser journeys remain deferred; tools regression coverage has not yet been generated.

## Remaining work

- Investigate production compatibility and restore appropriate strict-mode coverage later. The user authorized a temporary test-only relaxation to unblock current schema-dependent testing; the historical strict-mode failures remain recorded above.
- Agree the licensed frontend asset strategy before complete HMS browser journeys.
- Proceed to the Stage 2 capability/eligibility inventory and unchanged-application regression draft when requested. Final tools coverage gates have not been measured or met in Stage 1.
