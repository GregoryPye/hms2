# Stage 2E frontend component regression delivery

Status: implemented and independently exercised; seven contract failures remain open and the required gate stays red. Baseline `713d4c7bb7bf1d831f6f6595126990f24cf1839f` on `tools-test-harness`. Application code, configuration, views, routes, migrations, SQL and product dependency manifests are unchanged.

## Boundary and reproduction

```sh
python3 -m unittest discover -s tests/Environment -p test_runner.py -v
python3 tests/Environment/run.py prepare --profile frontend
python3 tests/Environment/run.py pr-check --profile frontend --cycles 2 --output /tmp/hms-2e-final
```

This independently runnable profile needs Node and a tiny canary image only. No PHP/MySQL, Chromium, production application entrypoint or private asset build is loaded. Public test-only packages are pinned and installed with lifecycle scripts disabled. Vue 2/Test Utils mount all four actual Tools components and their three shared selection components; the test transformer compiles their actual scripts and templates.

| Group | Actual components / contracts | Cases | Pass | Fail |
| --- | --- | ---: | ---: | ---: |
| `calendar.test.cjs` | ToolCalendar: permissions, time/ownership bounds, create/move/resize/cancel payloads, error recovery, cooperative cancellation, API/Echo order/duplicates/counts, responsive/timer cleanup | 56 | 52 | 4 |
| `list.test.cjs` | BookingCalendarList: initial mapping, current-user live updates, replace/duplicate/cancel, expiry, range, cancellation/error/loading/plugin callbacks, cleanup | 18 | 16 | 2 |
| `selection.test.cjs` | MemberSearch/MemberSelectTwo/SelectTwo: GET/native submit, actual nested selection events, filters/pagination/account identity, DOM/watchers, result formatting, plugin teardown | 19 | 18 | 1 |
| `forms.test.cjs` | ToolGrantModal/ToolUsageAddModal: native form action/verb/CSRF/identity/duration, current-member selection, title/button flags and modal callbacks | 5 | 5 | 0 |
| **New business cases per cycle** | **UI-01–05** | **98** | **91** | **7** |

FullCalendar, Axios, Echo, loading, Select2 and Bootstrap plugins are explicit recording doubles. Real Vue, jQuery DOM manipulation, moment/timezone and humanize-duration execute locally. Calendar storage permits duplicate public IDs and removes individual instances, so the double does not silently enforce application deduplication. API responses are explicitly queued; unexpected requests and unused queues fail tests. Fetch/XHR/WebSocket/beacon/jQuery Ajax are blocked and recorded independently of container network denial. Native form submit is observed locally, never navigated to a server.

The native modals contain no Axios/client validation flow to test. Their real hidden inputs, selector filters and serialized form data are asserted; server validation/persistence belongs to 2D, browser submission/integration to 2F. Grant and free-time tests do not invent new client behavior.

## Final evidence and findings

`/tmp/hms-2e-final/report.json` completed two fresh container cycles. Cycle one uses declaration order within files; cycle two uses Jest's seed-42 shuffle. All four groups change case order; every named case retains its status and passing-expectation count. Both cycles report 98 business cases, 91 pass / seven assertion failures / no skipped cases or suite-load errors, with 888 passing expectations each. Jest exposes passing expectations rather than a reliable total of all executed expectations, so this figure is not a PHP-style total assertion count.

The separate tooling check contributes two passing smoke cases per cycle. The overall command correctly exits **1/blocked**, with **20 passing check groups / two blocked groups** (one business group per cycle). All preflight, canary, network, credential/config rejection and cleanup gates pass. All nine host controller tests pass, including required business-versus-smoke selection and full-scope/nonempty/consistent JS coverage evidence checks.

| Finding | Reproduced result | Failing cases per cycle |
| --- | --- | ---: |
| DEF-03, existing | Another user's normal create increments the current-user count; cancel decrements it below zero | 2 |
| DEF-14, new | A second loading(true) hides the overlay while isLoading remains true, in both calendars | 2 |
| DEF-15, new | Both calendars execute their pending 100 ms layout callback after destruction | 2 |
| DEF-16, new | Member-result fields are interpolated as jQuery HTML instead of literal text | 1 |

The formatting fixture uses inert `<em>` markup only; no script execution, live member data or network request is involved. DEF-16 establishes unescaped markup handling, not a complete deployed exploit assessment. Ordinary interval/resize/Echo cleanup passes; pending-layout failures are asserted before harness timer cleanup. No expected-failure wrapper, skip, product fix or contract waiver makes the required gate green.

Early trials are not final evidence: the first large report records were corrupted by interleaved stdout/stderr, fixed with synchronous single-stream emission and an explicit coverage artifact allowlist. A selector assertion initially counted its outer wrapper as well as the hidden invalid-feedback node; that test assumption was corrected. Review also removed a double that silently deduplicated IDs, an unrealistic type-change characterization, and stale report reuse after crashes. Vue runtime errors and unexpected work are now checked even if application code catches/logs them.

## Coverage and remaining limits

Fixed scope: ToolCalendar, BookingCalendarList, ToolGrantModal, ToolUsageAddModal, MemberSearch, MemberSelectTwo and SelectTwo. `collectCoverageFrom` retains all seven even if unexecuted. Each cycle exports raw Istanbul counters and summary, plus original Jest JSON and JUnit. The controller validates exact scope, nonempty counts and consistent totals; missing required evidence blocks the check.

| Metric | Covered / total | Percent |
| --- | ---: | ---: |
| Statements | 508 / 508 | 100% |
| Executable compiled lines | 507 / 507 | 100% |
| Functions | 137 / 137 | 100% |
| Branches | 188 / 208 | 90.38% |

These are compiled SFC script/render metrics, including generated render/glue code and failing-test execution; line locations are not guaranteed one-to-one with original `.vue` source lines. They establish execution, not correctness. PHP/SQL, CSS, external plugin implementation, production assets and the rest of the frontend are excluded. Repeat counters are not added together. Initial vue2-jest inner Babel instrumentation plus outer Jest instrumentation overwrote calendar counters, falsely leaving render-only coverage; `transform.cjs` disables only the inner instrumentation so the assembled actual SFC is measured once. The 165-line trial figure is discarded; final coverage includes the calendar logic.

Uncovered branches remain: ToolCalendar 116/127, list 39/42, MemberSelectTwo 15/16, SelectTwo 15/17, and one of two branches in each of MemberSearch and both modals. These include generated render guards and unselected setting combinations; no whole-system coverage claim follows from 100% statements.

The range test proves the component calls its previous cancellation token and handles Axios cancellation rejection. It does not prove real transport abortion, arbitrary stale-response suppression, or cancellation on destruction. List initial data is assumed server-filtered; only live incoming broadcasts are owner-filtered here. Default ToolCalendar capability data permits the normal option without a user ID; this is characterized behavior, not an authorization approval. Actual FullCalendar rendering/interactions, real Select2/Bootstrap behavior, browser CSRF/cookies, deployed payload delivery and application-plus-browser journeys remain 2F. No enabling product hooks were needed for these eligible component tests. Backend/browser/full-profile aggregation were not rerun; earlier reports have a different common tooling identity and cannot be combined as current results. Consolidation remains 2G.

## Isolation, resources and identity

Each case freezes Date/moment time, controls promises, resets DOM/recordings, destroys wrappers and restores clocks/spies. Vue errors and unexpected transports fail teardown. Harness cleanup clears remaining fake timers after application cleanup contracts have asserted their behavior. Tests run non-root/read-only with no host checkout/config/socket, published ports or capabilities; component workers use network none and an explicit synthetic environment. Positive canary control and independent DNS/TCP/SMTP/MQTT/cross-bridge/child denial pass before Jest loads. No production credentials/configuration or live services were used.

Final observed filesystem peak/minimum free: **14.81 / 15.03 GiB**; incremental execution peak about **0.46 GiB**. Observed aggregate container memory was about **42.0 MiB**, with only one successful memory sample during these short runs; this sparse sample cannot establish frontend peak memory or a preparation budget. Bounded preparation evicted the previous harness-owned backend cache; the frontend preparation cache is retained for reuse. Final inspection found no test containers or private networks. Docker's existing `vfs` setup and resource limits remain unchanged.

Product digest: `65180871f036a42614559a7b206c573ea5bff4df482318776d5e624e7e7906c8`.
Tooling digest: `a1df0a3de9e26e88e98da3005e780dfa7b248c7b613f4abb5e9a6ab690152a46`.
Final report/prepared inputs/current files match. Product diff and `git diff --check` pass. No CI workflow activation, commit or push is included.

Separately, the user-requested four `.codex` files were copied unchanged from the supplied repository's `production` commit `64f3fa8dab684f5bec70dae48bd6627d7307d82e`. Byte equality was checked. Configured legacy model names are unavailable in this session; useful read-only scanner/tracer work used the available session model without altering the imported definitions. Those files are not copied into test containers or included in test execution.
