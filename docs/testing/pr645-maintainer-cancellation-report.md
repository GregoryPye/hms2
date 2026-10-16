# PR #645 maintainer-cancellation regression tests

Status: tests implemented and exercised against imported commit `944a1e79156c5956008c434b53035838918982cc` (upstream `173bfeaa`, NottingHack/hms2 PR #645). Product code is unchanged by this test increment. The requested failure situations remain ordinary failing contracts; no fix, expected-failure wrapper or skip was added.

## Scope and results

There are **47 new regression cases per cycle**: 12 manager units, 16 real HTTP/mail cases, 17 component cases and two actual browser journeys. They run twice. The new cases yield **40 passes and seven failures per cycle**; repeated runs do not increase unique coverage. Two additional Python controller regressions bring the control-plane suite to **17 passing tests**.

| New group | Cases/cycle | Pass | Fail | Evidence |
| --- | ---: | ---: | ---: | --- |
| Manager cancellation | 12 | 11 | 1 | Owner/ordinary/inductor/wrong-tool permission matrix, camel-cased tool identity, all three booking types, stable cancellation identity, notification payload and queue/removal faults |
| Real HTTP, ORM and mail | 16 | 15 | 1 | Actual middleware/Gates/binding, global permission, guest, correct recipient/no copies, repeat DELETE, self-cancellation, queue job/serialization, real rendered template, queue rejection, real database deletion fault and personal booking limit |
| Mounted ToolCalendar | 17 | 12 | 5 | Confirmation identity/time/loading guards, wording/confirm/dismiss, one selected DELETE callback, API/Echo orders, personal count/offered capacity and failure recovery |
| Actual browser | 2 | 2 | 0 | Desktop/mobile maintainer journey using real login/cookies/CSRF, FullCalendar/Bootstrap, ordinary denial, dismiss/confirm, self-cancellation, reload and independent persisted/event/notification-intent checks |

The broader verification also runs the existing 70 manager cases and all existing frontend/browser cases. Results are stable in default/reversed PHP order, seed-42 component reorder and exact reverse browser journey order:

| Run | Per-cycle leaf outcome | Runner checks over both cycles | Exit |
| --- | --- | --- | --- |
| Focused backend | 34 including six foundation cases: 32 pass / two failures, 320 assertions | 40 pass / four blocked | 1 |
| Backend units | 85 including three foundation cases: 80 pass / five failures, 260 assertions | 26 pass / four blocked | 1 |
| Frontend components | 115: 103 pass / 12 failures; 1,025 passing expectations, not total assertions | 20 pass / two blocked | 1 |
| Browser/integration | Ten actual journeys pass, including the two new ones | 112 pass / zero blocked | 0 |

No accepted run has test errors, skipped cases, missing/duplicate required checks, suite-load errors or malformed/missing result evidence. Existing four manager and seven component failures remain visible alongside the new failures. The changed upstream denial text is updated in the existing owner-cancellation assertion and passes. This is affected-scope verification, **not** a new full backend/SQL/HTTP three-profile aggregate; partial selections cannot satisfy that gate.

## The three discussed situations

**Personal booking count — DEF-03 extension.** With two personal bookings already counted, cancelling another member's normal booking changes the acting user's client count to one. API-first, Echo-first and API-only cases all fail the count invariant; a fourth case shows the normal-booking button is wrongly offered at a limit of two. A separate real HTTP case passes: after cancelling someone else's booking the server still rejects a third personal booking and retains the two existing ones. Cancellation failure and non-normal cancellations preserve the counter. The tests therefore distinguish misleading client allowance from correct server rejection.

**Competing confirmations — DEF-17.** The owner-maintainer component case reproduces both ordinary and maintainer setup paths, failing the requested single appropriate path contract. Actual desktop/mobile Bootstrap journeys nevertheless show **one ordinary owner confirmation**, no member-notification wording for self-cancellation, and no additional notification intent. Two visible popups, duplicate requests or duplicate emails are not claimed as reproduced defects. The failure records redundant competing setup, with a passing real-plugin boundary check that limits the user-impact claim.

**Notification before deletion — DEF-18.** A recording manager unit and an actual MySQL-trigger/Doctrine/HTTP case independently reproduce this. The trigger rejects DELETE in the disposable schema. The endpoint returns 500, the booking remains, no BookingCancelled event is dispatched, yet a success notice is already queued in the mail fake. Both no-notice-on-failed-removal assertions remain red. A distinct queue-rejection case passes: it produces an HTTP error without removal/event. The component's HTTP-500 case passes: it displays the generic cancellation error, keeps the event/count and clears loading. No real member receives any notice during testing.

## Harness and safety

The focused selector `--suite maintainer-cancel` runs named no-network manager and isolated HTTP/mail groups plus mandatory foundation/safety checks. Default backend runs include both groups; the unit file is excluded from the broad directory suite and included exactly once as its named suite. No SQL business routine or application isolation hook was changed.

HTTP mail assertions use a scoped Mail fake. One test uses the actual Laravel MailManager with Queue::fake to prove `send` produces exactly one SendQueuedMailable for this ShouldQueue mailable, then serializes/rehydrates the job without handling it. Another renders the real template using a checked local array mailer. It verifies recipient-name/tool-name escaping, subject and branding. Queue/bus/other transport guards and independent egress denial remain active. No SMTP service, general worker, scheduler or live transport is run.

Browser mail uses a strict test-owned port that accepts only BookingCancelledForMaintenance for the exact synthetic reader/payload and records an intent in tmpfs. It is **not** evidence of actual queue processing/delivery; MC-05 independently proves queue dispatch. Reset clears the recording, and PHP plus Python independently require one correct cross-member intent, none for self/denied cancellation, exact events, no retained bookings/usages/transactions/audit changes and four unchanged balances. A new controller negative rejects absent, wrong-recipient/tool, duplicate and owner-notification evidence. The real empty-lifecycle negative still exits 1 in both browser cycles.

Initial template trials exposed test setup issues: strict MailManager mock blocked renderer resolution, then the filtered snapshot omitted published mail themes because their path contains `vendor`. The scoped array renderer and exact `resources/views/vendor/mail` source exception fix these harness issues. Dependency vendors/private assets, hidden files, keys and unsafe inputs remain excluded; a new controller test verifies this distinction. One intermediate preparation was correctly rejected after harness edits changed its input identity. These trials are not accepted final evidence.

The source digest now includes previously filtered, already tracked mail views/themes; this is a snapshot-scope correction, not an application-file edit. All final reports/current files match product digest `a4b3783b7e6dadf681e4328f2857a2016feb6865e256111042305464d83049a5`, tooling digest `e6e19e66f83c60abd26d6af4a377ac9e5edfdd2c9e7e0cbdba7d7fdd7b23efb8` and SQL pin `12ba6da237b6a97eb92e7efc53bf81c34844bfa8`.

All safety and cleanup checks pass. A bounded scan of **322 accepted JSON/XML/log artifacts** found no private-key block, bearer-header or JWT pattern; fixtures/recipients are synthetic. Final inspection finds no test/preparation containers or private networks. Prepared frontend cache remains; browser/backend preparation was evicted when preparing frontend. Product diff and whitespace checks pass.

## Coverage and resources

The PHP allowlist preserves its previous 47 files and adds only the new mailable (**48 files**). The mailable covers **4/4 executable lines**, including actual queue/serialization/render cases. Focused PHP coverage is 88/231 core lines and 294/1,352 wider lines; the broader no-network unit run is 226/231 and 429/1,352. These partial-selection figures do not replace the earlier cumulative coverage baseline or claim final acceptance. PCOV supplies lines, not PHP branches; failing-test execution is included.

Seven compiled SFCs cover **518/518 statements, 517/517 lines, 140/140 functions and 192/212 branches (90.56%)** identically in both cycles. These are compiled script/render metrics including failures, not CSS/plugin/SQL or whole-system percentages. Actual browser journeys add integration evidence, not a percentage.

Sampled execution peak/minimum available disk was **27.21/2.63 GiB** for focused backend, **25.70/4.13** for units, **29.11/0.72** for browser and **20.19/9.65** for frontend. Browser and focused backend observed aggregate container memory about **1209/1012 MiB** over 22/29 samples. Frontend observed about 205 MiB over one sample; the unit observation sampled mostly idle workers and is not representative PHP peak evidence. Samples are not guarantees; browser headroom remains limited. Existing resource limits, storage driver and ownership-scoped cleanup remain unchanged.

## Reproduction

Use the [runner instructions](../../tests/Environment/README.md) and existing credential-free Docker prerequisites. Commands below are separate steps: the ordinary red contract runs intentionally return 1, so do not use `&&` to suppress later verification.

```sh
python3 -m unittest discover -s tests/Environment -p test_runner.py -v
python3 tests/Environment/run.py prepare --profile backend
python3 tests/Environment/run.py pr-check --profile backend --suite maintainer-cancel --cycles 2 --output /tmp/hms-pr645-backend-accepted
python3 tests/Environment/run.py pr-check --profile backend --suite backend-unit --cycles 2 --output /tmp/hms-pr645-unit
python3 tests/Environment/run.py prepare --profile browser
python3 tests/Environment/run.py pr-check --profile browser --cycles 2 --output /tmp/hms-pr645-browser
python3 tests/Environment/run.py prepare --profile frontend
python3 tests/Environment/run.py pr-check --profile frontend --cycles 2 --output /tmp/hms-pr645-frontend-accepted
```

Reports, JUnit/Clover/Jest/Istanbul/browser state artifacts and bounded logs are ignored generated files under those output directories. No GitHub workflow, issue, commit or push is included. The task remains open for known findings and later acceptance; no product fix was made.
