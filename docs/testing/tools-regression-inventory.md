# Tools regression inventory and coverage scope

Baseline: HMS2 `f08bab4726307c8a75bbcb5a07c10b0bd9a51a45` on `tools-test-harness`; pinned SQL `12ba6da237b6a97eb92e7efc53bf81c34844bfa8`. Product source is unchanged by 2A. This includes Stage 1's authorized `generated` quoting fix. MySQL 8.4.6 uses the explicitly authorized temporary modes `ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` in the server and Laravel sessions. Production compatibility and strict-mode coverage remain TODOs.

Stage 2C implementation/evidence uses `5aad773f`; Stage 2D uses `f9230ad4` and Stage 2E uses `713d4c7b`, all with unchanged product source; see the [backend delivery report](stage2c-backend-report.md). Delivered partitions do not imply completed transport/browser layers or final coverage gates.

This is a versioned planning inventory, not a claim that the planned business tests exist. Each row is a scenario family with named partitions to expand into parameterized cases in its owning chunk. IDs are stable; append cases rather than renumbering existing IDs. No business-family row is completed by a foundation smoke check. Stage 2B adds actual SQL cases, 2C backend PHP cases, 2D routed HTTP cases and 2E frontend component cases; their executed pass/defect/deferred accounting is recorded in the respective delivery reports.

Eligibility: **R** means runnable using current injection points and synthetic isolated services; **H** means deferred until an application isolation hook is delivered in Stage 3; **B** means blocked on a recorded non-hook prerequisite; **D** means a suspected defect/contract decision must be recorded when reproduced. R is a feasibility assessment, not an executed result. Later implementation may discover a narrower missing hook; record that change explicitly. No unsafe case may run merely because its initial eligibility is R.

Execution: backend owns PHP/SQL/HTTP, frontend owns components, and browser owns sandboxed tooling/future actual HMS journeys. All delivered foundation/tooling profiles passed two independent and cumulative cycles; see the [split verification report](suite-split-report.md). Foundation rows below are verified, while each business family below records its own delivery status.

## Implemented foundation evidence (2A)

| ID | Actual test / check | Purpose | Status |
| --- | --- | --- | --- |
| FND-01 | `tests/Foundation/Unit/SupportTest.php::testSyntheticBuildersAreDeterministicAndDoNotPersist` | Synthetic identities, reserved email domain, deterministic cards/tools, explicit persistence. | Passed in both cycles; see 2A and split reports. |
| FND-02 | `SupportTest::testFixtureKeysRejectArbitraryInput` | Reject arbitrary user fixture input. | Passed in both cycles; see 2A and split reports. |
| FND-03 | `SupportTest::testClockRestoresMutableImmutableAndTimezoneAfterFailure` | Nested PHP clocks and exception cleanup; does not fake MySQL time. | Passed in both cycles; see 2A and split reports. |
| FND-04 | `tests/Foundation/Integration/DatabaseSupportTest.php::testFixturesCrossBothOrmsAndRollbackRestoresStateAfterFailure` | Persist user/role/permission/card/tool/content/meta, cross-read actual schema, roll back both connections and clear Doctrine after failure. | Passed in both cycles; see 2A and split reports. |
| FND-05 | `DatabaseSupportTest::testRecreationRemovesCommittedRoutineEffectsAndRestoresRealSchema` | Test-owned committing procedure, stale identity map, full schema/routine reset and same fixture reuse. | Passed in both cycles; see 2A and split reports. |
| FND-06 | `DatabaseSupportTest::testUnexpectedTransportsFailAndExplicitExpectationsRecordCalls` | Reject stray mail/bus/HTTP work, record an explicitly expected call through an existing binding. | Passed in both cycles; see 2A and split reports. |
| SAFE-01 | Existing runner preflight, sentinel/config/cache rejection, canary and child-process probes | Reject production configuration/credentials before bootstrap; verify no egress on no-network/private-network paths. | Retained in cumulative command; see Stage 1 and 2A reports. |
| SAFE-02 | Existing frontend/browser tooling smoke and cleanup checks | Sandboxed browser egress denial, no host binds/ports/capabilities, disposable restart/cleanup. | Retained; actual HMS journeys still B below. |

## SQL scenario families (2B)

Implemented location: `tests/Integration/Tools/Sql/*Test.php`; exact counts and reproductions are in the [2B SQL report](stage2b-sql-report.md). Source: pinned `database/database/procedures/{sp_tool_*,fn_tool_in_maintenance_slot,fn_check_permission,sp_check_rfid,sp_get_details_from_rfid}.sql` and the referenced views. SQL functions live in the `procedures` directory too. Dependency loaders retain original SQL; no routine is rewritten for testing.

| ID | Partitions and observable assertions | Eligibility / dependencies | Status |
| --- | --- | --- | --- |
| SQL-01 | Status query: single/all/unknown tool, FREE/IN_USE/DISABLED and status reason, correct tool identity. | R; synthetic tools. | Executed; status/access suite. |
| SQL-02 | Known/unknown/expired/lost RFID, inactive/non-member and missing general permission: reject with no usage or balance mutation. | R; cards, roles and referenced membership views. | Executed; state-based cards/general permission; membership is permission-driven, not a separate SQL status check. |
| SQL-03 | Restricted/unrestricted tool: ordinary user, inducted user, inductor and maintainer permission combinations. | R; real role/permission joins. | Executed; real permission joins and user scope. |
| SQL-04 | Disabled tool and maintenance slot: maintainer exception, non-maintainer rejection, stored disabled status preserved during maintainer use. | R; SQL time uses bounded relative booking fixtures. | Executed; disabled/maintenance exception and preserved status. |
| SQL-05 | Sign-on/off: usage start/completion, repeat calls, no active usage, tool/user isolation and returned status. | R; recreate after committing paths. | Executed; DEF-06 repeated-sign-on contract failure retained. |
| SQL-06 | Pledged credit: no award, positive/negative duration credits, partial consumption, expiry/time boundaries where applicable. | R; actual duration/transaction rows. | Executed; no expiry feature exists in this SQL. |
| SQL-07 | Charge: zero/full/mixed rates, elapsed versus active time, integer rounding and persisted financial result. | R; synthetic account and balance fixtures added when needed. | Executed; rounding, persisted amounts and actual payment allocation. |
| SQL-08 | Maintenance charge exemption, insufficient credit and transaction-type override. | R; relevant status, permission and accounting rows. | Executed; DEF-08 duplicate maintenance transaction failure retained. |
| SQL-09 | Repeated charging, invalid usage and SQL error/rollback: no double charge and exact committed/rolled-back outcomes. | R; independent reset for each case. | Executed; DEF-07 financial rollback failures retained; other denial/reset cases pass. |
| SQL-10 | Card induction: invalid/valid inductor and inductee, duplicates, grants/audit attribution and chargeable-tool free time. | R; persisted roles/audit rows, no external messaging. | Executed; all induction partitions pass. |
| SQL-11 | Booking retrieval/calendar/status helper functions: tool/time filtering, interval boundaries and maintenance detection. | R; PHP and SQL clocks treated separately. | Executed; booking boundaries/removal/retrieval pass; LEGACY-01 channel dependency fails. |
| SQL-12 | Relevant reporting views: usage/date/member summaries agree with source rows. | R for tools dependencies; broader legacy laser reporting B pending dependency/scope inventory. | Usage/member/inductor summaries executed; two grouped views blocked by COMPAT-02; broader vending summary inventoried only. |
| SQL-13 | Normal booking versus usage entitlement: record current reservation behavior separately from maintenance exceptions. | D; task question about advisory normal bookings remains open. | Executed characterization: another user’s normal booking does not block sign-on; product decision remains separate. |

## Backend scenario families (2C)

Implemented locations: `tests/Unit/Tools/*Test.php` and `tests/Integration/Tools/Backend/*Test.php`; exact evidence is in the [2C report](stage2c-backend-report.md). Sources: `app/HMS/Tools`, `Factories/Tools`, `Entities/Tools`, `Repositories/Tools`, `User/Permissions/RoleManager.php`, role listeners, statistics action and tool collector.

| ID | Partitions and observable assertions | Eligibility / dependencies | Status |
| --- | --- | --- | --- |
| PHP-01 | Tool factory defaults, default disabled/hidden/restricted states, prices and booking limits. | R; factory is independently constructible. | Implemented: BackendUnit factory/flag partitions. |
| PHP-02 | Create tool: permission templates, existing permission reuse, role persistence and default status. | R; real/double repositories and explicitly expected event dispatch. | Implemented: unit reuse boundary plus actual role/permission persistence and shared-permission reuse. |
| PHP-03 | Edit tool: allowed details/validation, duplicate names and persistence; internal rename/role identity stays an explicit decision. | R; rename D due existing unfinished behavior. | Implemented: checkbox/details update and unique constraint; DEF-04 rename contract remains failing. |
| PHP-04 | Enable/disable: status/reason effects and reason presentation; no invented HTTP endpoint or notification behavior. | R; notify parameter unimplemented, not a completed feature. | Implemented: status/reason and real persistence; notify remains a product TODO. |
| PHP-05 | Remove tool: references from bookings/usage/roles and resulting constraints or cleanup. | R; actual schema constraints, ambiguous outcomes recorded. | Implemented: named removals, soft-delete/permission retention, actual FK rejection and failed-removal atomicity contract. |
| PHP-06 | Grant/revoke user/inductor/maintainer: duplicate/missing operations, role persistence and authority split. | R; constructor-injected repositories. | Implemented: all three grant types, duplicate/missing role-presence branches and actual ACL/audit persistence. |
| PHP-07 | Real role-update listener attribution and retained user versus elevated membership-end roles. | R for local audit/member effects; unrelated outbound listeners guarded explicitly. | Implemented: actual audit actor/system/approval cases; both revocation listeners, current/young, retained versus elevated roles and non-member no-op. |
| PHP-08 | Normal booking permission/limits: global/per-tool, restricted/unrestricted, outstanding count and no mutation on denial. | R; repository doubles plus selected persistence cases. | Implemented: capability matrix, outstanding limits, no-write denial and direct-manager capability-consistency contract. |
| PHP-09 | Induction/maintenance booking permission combinations and maintenance exceptions. | R; deterministic users/roles/time. | Implemented: induction/maintenance restriction matrix, authenticated-user fallback and long maintenance booking. |
| PHP-10 | Invalid/equal/reversed/past times, maximum duration, exact boundaries and London DST/UTC conversion. | R; PHP clock helper. | Implemented: time tolerance/equality/reversal/max boundaries; London DST in rules and actual UTC ORM persistence. |
| PHP-11 | New booking clashes: all overlap shapes, adjacency and isolation between tools/users. | R; real query suite plus manager decisions. | Implemented: three creation types plus real overlap/adjacency/containment and tool-isolation queries. |
| PHP-12 | Edit booking: owner, tool mismatch, ongoing/unchanged values, permitted changes and persisted events. | R; update clash checking D. | Implemented: owner/tool checks, omitted fields and ongoing edits; DEF-01 and ongoing-invalid-end contracts remain failing. |
| PHP-13 | Cancel booking: removal, missing record behavior and stable event tool/booking identity. | R; real persistence and event contract. | Implemented: ownership rejection, real cancellation and stable removed identity; absent HTTP binding remains 2D. |
| PHP-14 | Current/next/future/day/week/month repository queries: inclusivity, cancellation and per-tool/user filtering. | R; Doctrine/custom Carbon mapping and real MySQL. | Implemented: real current/next/count/future/type/day/week/month/between inclusivity and user/tool filters. |
| PHP-15 | API/broadcast serialization: IDs, types, field names, UTC/offset dates and before/after booking identity. | R for local serialization; mutable before/after alias D. | Implemented: entity JSON, timezone fields, channels/cancellation data and event round-trip; DEF-02 original/current alias failure retained. |
| PHP-16 | Recent usage, free-time/member summaries and zero/no-usage cases. | R; real repositories/views. | Implemented: signed factory durations, no-usage/null and actual free-time/query/status/ORM-SQL readback. |
| PHP-17 | Statistics cache, current/prior month totals and role counts; metric labels/state values. | R; deterministic time/cache and real fixtures. | Implemented: month/current-member totals, negative duration exclusion, actual six-hour cache and collector states/labels; coverage gaps explicit in 2C report. |
| PHP-18 | Event/listener registration and dispatch contract; one isolated queue serialize/rehydrate round-trip. | R for registration/recording/serialization without listener execution; full MQTT listener execution H. | Implemented: production registrations and real queued-wrapper serialization without handle; actual MQTT execution remains HOOK-01. |

## HTTP scenario families (2D)

Implemented location: `tests/Feature/Tools/*Test.php`. Sources: `routes/{web,api}.php`, tool/booking web and API controllers, `Api/SearchController`, middleware, guards and Doctrine binding.

| ID | Partitions and observable assertions | Eligibility / dependencies | Status |
| --- | --- | --- | --- |
| HTTP-01 | Anonymous/session/API authentication; verified/unverified email and enabled/disabled feature gates. | R; use supported helpers plus a small local credential/token flow. | Implemented: actual anonymous/session/Passport and local credential/token flows; web verified-email versus API omission and feature redirects characterized. |
| HTTP-02 | Global/per-tool authorization, ownership and mismatched booking/tool route bindings: exact denial response and no writes/events. | R; actual middleware, no blanket bypass. | Implemented: actual global/per-tool Gates, owner/tool mismatch, binding/response and persisted no-write/event checks. DEF-09 is a direct-manager ambiguity; API store enforces tools.book. |
| HTTP-03 | Tool list/detail/settings, hidden-list versus direct access and escaped status/reason. | R; deterministic content/meta; no external asset fetch required for response assertions. | Implemented: actual pages/layouts, hidden-list/direct-detail distinction, editor visibility, escaped disabled reason, calendar/settings/usage responses. Asset URL lookup only is substituted. |
| HTTP-04 | Create/update/delete validation, duplicate/missing records and persistence/error responses. | R; existing routes only. | Implemented: actual create/update/remove, duplicate-name/field validation and missing binding. Referenced-deletion atomicity remains reproduced at the 2C manager boundary (DEF-11), not waived. |
| HTTP-05 | Booking API create/query/edit/cancel: status codes, validation, payload fields/timezones and persisted outcomes. | R; manager cases in 2C, explicitly guarded event dispatch. | Implemented: typed create, date validation, tool-scoped query, offset edit/cancel and persisted outcomes; DEF-01 overlap and advertised show-route contract (DEF-12) remain failing. |
| HTTP-06 | Grant/revoke/free-time endpoints: authorized/denied/invalid/duplicate cases, audit and financial outcomes. | R; no live service transport. | Implemented: grants/revokes authority/idempotency, real audit actor/target, grant lists, signed free-time/no financial charge and invalid targets/fields; missing-amount contract (DEF-13) remains failing. |
| HTTP-07 | Member search: authority, current-member restriction and response visibility. | R; shared member fixture extensions only as needed. | Implemented: actual search/pagination, empty/path/query/account cases, tools-only forced-current filtering and privileged-address visibility. Large-result pagination boundaries remain a gap. |

## Frontend scenario families (2E)

Proposed location: standalone frontend test tooling alongside `tests/Environment/frontend`, extended without changing product package manifests. Sources: actual `resources/js/components/Tools/*.vue`, `MemberSearch.vue`, `MemberSelectTwo.vue` and required local wrappers.

| ID | Partitions and observable assertions | Eligibility / dependencies | Status |
| --- | --- | --- | --- |
| UI-01 | Calendar booking create/move/resize/cancel callbacks, payloads and ownership/permission controls. | R; actual component, local FullCalendar/Axios doubles. | Implemented: actual mounted ToolCalendar callbacks, type/time payloads, permissions/ownership, max duration/count and native confirmation ports. |
| UI-02 | Loading, request cancellation, validation/network errors and recovery without duplicate/stale state. | R; deterministic promise/clock control. | Implemented: cooperative range cancellation, create/patch/cancel success and four error shapes, revert/loading recovery; repeated-true overlay contracts fail (DEF-14). Actual transport cancellation/freshness remains outside the double. |
| UI-03 | API/broadcast arrival order, duplicates and current-user count versus another user's bookings. | R; cross-user counter D. | Implemented: both API/Echo orders, duplicate creates/cancels, same-type changed events and non-normal counts. Cross-user create/cancel contracts fail (DEF-03). |
| UI-04 | Booking list filters, date/time presentation and component cleanup. | R; local Echo/calendar/plugin doubles. | Implemented: list owner-filtered live updates, initial mapping, London range, expiry timer, responsive callback and ordinary interval/resize/Echo cleanup; both pending layout callbacks survive destruction (DEF-15). |
| UI-05 | Grant/member-selection/free-time/usage forms: selected identity, permissions, invalid input, request failure and recovery. | R; actual Vue behavior where implemented; Blade/HTTP paths owned by 2D/2F. | Implemented: actual native grant/free-time fields, CSRF/verb/identity/duration, modal ports, shared search/selector query/pagination/events/watchers/plugin teardown. Member-result text contract fails (DEF-16). No invented Axios validation/recovery for native forms; server validation is 2D and browser submission is 2F. |

## Integrated journeys (2F) and enabling hooks (3/4)

Proposed browser location: `tests/Browser/Tools`. Actual browser application journeys are distinct from Stage 1's HTML tooling fixture.

| ID | Partitions and observable assertions | Eligibility / dependencies | Status |
| --- | --- | --- | --- |
| WEB-01 | Tool visibility/details and denied access on desktop/mobile against local HMS. | B; credential-free licensed asset strategy plus relevant HTTP/fixtures. | Planned; blocked |
| WEB-02 | Authorized booking lifecycle, actual calendar integration and key failures on desktop/mobile. | B; assets, relevant 2C/2D/2E evidence and controlled transports. | Planned; blocked |
| WEB-03 | Selected grant/free-time journey and permission denial against local HMS. | B; same asset/workflow prerequisites. | Planned; blocked |
| HOOK-01 | Execute queued booking notifier and verify MQTT connect/publish/disconnect plus failure handling. | H; `app/Listeners/Tools/NotifyNhToolsSubscriber.php::poll` directly constructs `MQTTClient`. Smallest proposed hook: inject a publisher/factory preserving client/topic/payload semantics; review in Stage 3, dependent tests in Stage 4. | Explicitly deferred; no hook implemented |
| HOOK-02 | Deliver actual booking broadcasts and consume in browser over websocket. | B; optional disposable local websocket strategy/asset decision. Existing broadcasting bindings permit recording/serialization now; no new hook presumed. | Planned integration; not run |

## Defect/decision register

These remain hypotheses from code inspection until a named test reproduces them. A failing reproduction is not normalized into an approved behavior assertion. See the task's questions for decisions; retain explicit expected-failure reporting if later agreed.

| ID | Finding | Owning scenario / next step |
| --- | --- | --- |
| DEF-01 | Booking edits omit clash checking. | PHP-12; reproduced by failing update overlap assertion; seek intended contract. |
| DEF-02 | BookingChanged holds original/current references to one mutable object. | PHP-15; reproduced by failing independent before/after assertion. |
| DEF-03 | Broadcast handlers change current-user count for another user's booking. | UI-03; reproduced by two failing cross-user create/cancel count contracts in 2E; both API/Echo order cases otherwise pass. |
| DEF-04 | Tool internal renaming does not coordinate permission/role names. | PHP-03; decide prohibition versus coordinated rename separately. |
| DEF-05 | Normal reservations are not exclusive operating entitlement in SQL sign-on. | SQL-13; confirm current behavior versus requested future enforcement. |
| DEF-06 | A second SQL sign-on while a tool is IN_USE is accepted. | SQL-05; reproduced by the dedicated repeated-sign-on contract test. Decide rejection versus deliberately supported concurrent sessions; no product fix included. |
| DEF-07 | Charging rollback is not atomic when nested transaction logging starts/commits its own transaction. | SQL-09; first financial-insert fault leaves usage CHARGED; mixed-charge second-insert fault also retains the first zero-value transaction and consumes pledged state. Dedicated failing assertions preserve both reproductions. |
| DEF-08 | Disabled maintainer use with pledged time logs two zero-value transactions instead of one maintenance record. | SQL-08; exemption falls through into pledged-time logging; balance and pledged duration remain unchanged. |
| LEGACY-01 | `sp_tool_set_google_channel` references absent `tl_google_notifications`. | SQL-11; error 1146 on the real migrated schema; only reference in the pinned archive is the procedure itself, with no HMS app/migration caller/definition found. No substitute table invented. |
| COMPAT-02 | `vw_tool_users` and `vw_laser_usage` reject queries under the retained ONLY_FULL_GROUP_BY mode. | SQL-12; both reproduce MySQL error 1055. Production modes remain unknown; no mode relaxation or SQL rewrite included. |
| DEF-09 | Direct normal-booking manager calls can succeed while the capability summary denies general tools.book permission. | PHP-08/09; failing consistency contract. Route/middleware protection is not assessed here and belongs to 2D; decide intended manager boundary. |
| DEF-10 | Editing an ongoing booking with unchanged start accepts an end before that start. | PHP-12; failing time-boundary contract; other ongoing-edit cases pass. |
| DEF-11 | Failed deletion of a referenced tool leaves its roles soft-deleted while the tool survives. | PHP-05; actual foreign-key failure followed by a failing atomicity assertion. No product transaction change included. |
| DEF-12 | The registered GET booking-resource show route calls a controller with no show method and returns 500. | HTTP-05; failing advertised-resource contract. Decide whether to implement show or remove the advertised route; no controller/route change included. |
| DEF-13 | Free-time hours/minutes validate as optional but are read unconditionally; omitting the amount returns 500. | HTTP-06; failing validation/no-write contract. Decide meaningful zero/omitted/negative amount semantics separately; no product fix included. |
| DEF-14 | Repeated loading(true) hides the active overlay in both calendar components. | UI-02; two failing idempotent-loading contracts; isLoading remains true while loader is hidden. |
| DEF-15 | Both calendars leave their 100 ms layout timeout scheduled after destruction. | UI-04; two failing post-destroy callback contracts. Interval/resize/Echo cleanup passes; harness clears residual timers afterward. |
| DEF-16 | MemberSelectTwo interpolates API member fields directly into jQuery HTML. | UI-05; safe inert em-markup fixture becomes an element instead of literal text. Failing text contract; no script execution or live API data used. |
| COMPAT-03 | Statistics duration formatting emits a PHP 8.4 float-to-integer precision deprecation. | PHP-17; displayed from GenerateToolStatistics.php:115, reporting assertions pass. No deprecation-as-failure policy added. |
| COMPAT-01 | Timestamp migrations reject zero dates under strict mode. | Stage 1 report and task TODO; relaxed mode authorized, production mode unconfirmed. |

## Coverage and suite conventions

`tests/regression.xml` is the authoritative PHP coverage allowlist, fixed before business-test delivery. Core scope is `app/HMS/Tools` and `app/HMS/Factories/Tools`. The wider scope additionally includes tools entities/repositories, web/API controllers, tools events/listeners, statistics action/collector, selected role/audit/membership/authentication support and authorization middleware explicitly named in that file. Uncovered files remain included, even for the foundation-only draft. Additions/changes to this scope need a reason in the task; never narrow it to hide gaps.

PHPUnit's Clover and text reports measure executable PHP lines; PCOV does not supply branch coverage. No SQL, Blade or JS coverage is inferred from them. SQL scenario IDs above are the coverage ledger for 2B. Frontend Istanbul measures all seven compiled SFCs separately (including render glue and failing tests): 508/508 statements, 507/507 lines, 137/137 functions and 188/208 branches in 2E. No external plugin/CSS or whole-frontend coverage is inferred; browser journey evidence arrives in 2F. The original proposed final gates remain Stage 4 acceptance targets, not 2A claims.

| Suite | Current selection / convention | Status |
| --- | --- | --- |
| FoundationUnit | `tests/regression.xml`, `FoundationUnit`; PHPUnit 10, no network, guarded vendor entrypoint. | Implemented in 2A |
| FoundationDatabase | Same config, `FoundationDatabase`; real private MySQL, explicit reset strategies. | Implemented in 2A |
| SQL and backend integration | `tests/Integration/Tools`; four named SQL and four backend database suites. Backend selectors retain safety/schema prerequisites. | Implemented 2B/2C; findings remain open |
| Backend unit | `tests/Unit/Tools`, `BackendUnit`, no network or kernel. `--suite backend-unit` starts no MySQL. | Implemented 2C; findings remain open |
| Routed feature | `tests/Feature/Tools`, five `Http*` groups; real guarded kernel/middleware, disposable MySQL and `--suite http`. | Implemented 2D; findings remain open |
| Component | Pinned Jest/Vue Test Utils, actual seven-SFC compilation/mounting and strict recording ports; independent frontend profile. | Implemented 2E: 98 cases, 91 pass / seven failures; see [report](stage2e-component-report.md). |
| Browser | Pinned Playwright/Chromium with sandbox enabled. | Tooling only; actual journeys blocked for 2F |

Use PHPUnit here for the narrowly scoped foundation classes; Pest remains available from the existing lockfile for later domain suites. Do not alter the legacy broad PHPUnit bootstrap to bypass this suite's preflight. The cumulative `pr-check` action selects only delivered suites, records explicit asset deferrals and does not claim later chunks passed.
