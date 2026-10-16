# Stage 2F integrated browser journeys

Status: delivered and verified. All selected journeys and required safety/state/cleanup checks pass in both clean cycles. Trial runs are not accepted evidence. Baseline is `0688f270` on `tools-test-harness`; application code, routes, views, SQL, migrations and product dependency manifests remain unchanged by this increment.

## Reproduction and boundaries

```sh
python3 -m unittest discover -s tests/Environment -p test_runner.py -v
python3 tests/Environment/run.py prepare --profile browser
python3 tests/Environment/run.py pr-check --profile browser --cycles 2 --output /tmp/hms-2f-browser-final
```

The independently selected browser profile prepares its exact PHP/MySQL prerequisite and public-only frontend bundle. It invokes actual HMS routes/controllers/middleware through a test-owned PHP server, actual Doctrine/MySQL persistence and actual local credential login. It uses no authenticated-user helpers, fake HTTP responses, fake calendar/plugin implementations or test HTTP reset endpoints. A separate CLI process seeds/resets/verifies synthetic data. Browser, server and MySQL communicate only on a fresh internal isolated bridge with no published host ports.

| Journey | Actual behavior checked | Viewports |
| --- | --- | --- |
| Visibility/settings | Anonymous redirect/login, ordinary versus editor hidden-tool visibility and edit controls, actual settings page and logout | Desktop 1280×900; mobile 390×844 |
| Booking | Real responsive FullCalendar, pointer selection and Bootstrap confirmation, Axios create, reload/readback, pointer move and resize, invalid-type rejection, confirmation/cancel and empty persisted result | Both |
| Grant | Actual modal, Select2 current-member API search/selection, native POST with PATCH override/CSRF, grant list and persisted target/tool-role/acting-administrator audit | Both |
| Denials | Missing/invalid web CSRF, wrong-password form feedback, anonymous API 401, HTML unauthorized redirect/flash, authenticated API permission denials and no domain writes | Both |

There are eight journey cases per cycle, each covering a workflow rather than one assertion. Each gets a fresh browser process/context and explicit database/session/event reset. Cycle two reverses both journey and viewport order. Independent post-journey CLI checks verify empty booking/financial state, unchanged balances, exact grant/audit identities or the booking event sequence (create, move, resize, cancel). The selected access journey is grant; a free-time journey through the wider admin profile screen is not included. Its real HTTP and component contracts remain covered in 2D/2E.

## Approved public asset strategy

The user approved a public-only test bundle. Its separate manifest/lock pins the relevant product-library versions, including Vue/compiler 2.7.16, all FullCalendar packages at 6.1.19, Axios, Bootstrap Confirmation, Select2, moment/timezone and loading overlay. Webpack compiles the actual four Tools components, three shared selection components and Flash, plus repository-owned component/theme styles and public Bootstrap/Select2 styles. Asset compilation runs without network. Product entrypoints/manifests remain untouched; private Font Awesome Pro and remote fonts are omitted. No registry credentials or private assets are used.

The test entrypoint supplies the selected route helpers and a recording Echo boundary. Actual application HTTP/ORM behavior and actual browser/calendar/plugin rendering/interactions run; live Pusher/websocket delivery does not. Only date navigation uses the public calendar API; creation/movement/resizing/cancellation use DOM pointer/plugin interaction. Tests wait for the existing 100 ms layout workaround and real loading-overlay transition, paint drag mirrors before mouseup, and expose resize handles by hovering. These are harness readiness measures, not product fixes or direct component-method substitutes.

Mobile means a narrow responsive viewport with mouse interaction, not touch-device gesture coverage. Private icons, typography/visual parity, the complete production asset bundle, cross-browser behavior and a full application navigation inventory are not claimed. Echo delivery and direct queued MQTT remain the existing separate transport/deferred-hook work. No new product isolation hook was needed for this selected set.

## Runtime isolation and resource design

The full upstream multi-browser image exhausted disk during integrated preparation on this managed vfs host. A supported Playwright 1.56.0 Chromium-headless-shell-only installation replaces unused Firefox/WebKit content. Public asset dependency installation/build uses the smaller pinned Node image. Prepared PHP/Chromium filesystems are copied unchanged into a single final Docker layer using a standard multi-stage recipe, reducing vfs parent-tree duplication. Runtime paths/environment are explicit. Only exact registered owned intermediate images/build-cache IDs are released; borrowed resources, final runtimes and application/source/MySQL prerequisites remain protected. No daemon restart, storage-driver change, global prune or direct Docker-data-directory edit is used.

Chromium sandboxing, the established seccomp policy, non-root users, read-only source/rootfs, capability drops, no-new-privileges and existing resource limits remain mandatory. The browser has no application volume, DB credentials or signing keys. PHP storage and browser profiles are disposable tmpfs. The real server alone overrides guarded array sessions to a precisely checked local file-session path and uses one generated synthetic Passport keypair per cycle. Real wall time avoids expired cookie/JWT artifacts. Application middleware issues the actual API cookie; browser requests carry actual cookies/CSRF.

Before application journeys, both PHP and browser profiles independently prove production/credential/config/cache rejection, no default route, external DNS/TCP/SMTP/MQTT denial, cross-bridge canary denial and child isolation. Browser tooling also tests actual unintercepted HTTP/websocket egress denial. Journey contexts add same-origin request allowlisting, websocket denial and service-worker blocking. Selected booking events are faked before Laravel can dispatch ShouldBroadcast, while actual local role audit executes synchronously; no general worker, scheduler, real mail/payment/MQTT client or remote HMS instance runs.

## Reporting and trial corrections

Journey JUnit and JSON must agree on identity/outcome. Every reset must emit an explicit verified marker. Every state check must exit successfully and emit verified semantic state; the controller independently checks bookings, balances/transactions, events and grant/audit identities. A deliberate empty-booking-lifecycle negative must exit 1. Missing, contradictory, stale, unverified or incomplete evidence blocks the profile and cannot satisfy aggregation. Reports export no raw HTML, storage-state/cookies, request headers, bearer/CSRF material or signing keys. Failure diagnostics are bounded to errors, synthetic route/method/status information and calendar geometry/state.

Early trials exposed infrastructure and test assumptions rather than accepted product behavior: full-image/disk exhaustion, a shared-database helper's missing local variable, a duplicate Select2 factory invocation in the test entrypoint, HTML denial redirect semantics, pending response-wait rejection during teardown, hover/paint/overlay timing, and Laravel's CLI exception handler returning zero after an uncaught assertion. The last issue is explicitly guarded by catch/nonzero status, post-assertion markers, independent semantic validation and the real negative check. No trial's apparent state pass is accepted as final evidence. Existing lower-layer DEF findings remain open; no product behavior was modified to make these journeys pass.

## Final verification

`/tmp/hms-2f-browser-final/report.json` completes both fresh cycles with exit **0/pass**: **100 passing runner check groups, zero blocked or skipped groups**. Each cycle contains eight passing actual journey cases (16 executions overall), eight verified resets, eight independently verified domain-state checks and the mandatory tooling/application/safety/negative checks. These journey cases are included in the 100 check groups, not additional groups. Cycle two's eight journey names are the exact reverse of cycle one's; all identity/outcome/state reports agree. All **15 host controller tests** pass. Chromium reports **141.0.7390.37**, with Playwright **1.56.0** and sandbox enabled.

The real empty-lifecycle negative exits 1 in both cycles and is correctly counted as a passing *harness rejection* check. Every successful booking journey leaves zero bookings and exactly NewBooking → BookingChanged → BookingChanged → BookingCancelled recorded events. Every successful grant leaves one target maintainer grant and one audit entry with the exact target/role/acting administrator. Denial/visibility journeys preserve empty domain/audit/event state. All journeys preserve zero financial transactions and all four synthetic balances.

Final sampled runtime filesystem peak/minimum free: **27.82 / 2.02 GiB**; incremental execution peak **4.52 GiB**. Observed aggregate container memory peak was about **1060.1 MiB** over **17** successful samples; this is a sampled observation, not a resource guarantee. Final cached preparation peaked at about **24.53 GiB** with **5.31 GiB** minimum free and **1.23 GiB** incremental use. Its memory sampler had no successful sample, so preparation memory is unmeasured rather than zero. Cold image-build failures and intermediate trials are not folded into these successful cached-preparation figures.

Both final runtime images have one filesystem layer: prepared PHP about 635 MB, Chromium about 929 MB (decimal image sizes). Reused preparation remains checksum-guarded. Final inspection finds no test containers or private networks; registered browser and its explicit backend preparation caches are retained. Default combined execution now cleans both sets at completion; standalone `clean --profile all` also removes both using ownership checks. No broader Docker cleanup was performed.

The report/prepared manifests/current files match baseline `0688f270ad4d4676d4c6cb22412a9b5f09752ceb`, SQL pin `12ba6da237b6a97eb92e7efc53bf81c34844bfa8`, product digest `65180871f036a42614559a7b206c573ea5bff4df482318776d5e624e7e7906c8` and tooling digest `c6cc4c0016a83e8808266ade33890e422d767a5ae6fcef9b857e4dfa0741a907`. Artifact scanning found no private-key, bearer or JWT material. Product diff and `git diff --check` pass. No workflow activation, issue, commit or push is included.

Earlier backend/frontend reports have a different common tooling identity; full current three-profile regression remains Stage 2G. Browser journey evidence adds no invented PHP/SQL/JS coverage percentage and does not waive earlier red contracts or final coverage targets.
