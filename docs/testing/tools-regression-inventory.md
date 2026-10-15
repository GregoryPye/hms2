# Tools regression inventory and coverage scope

Baseline: HMS2 `f08bab4726307c8a75bbcb5a07c10b0bd9a51a45` on `tools-test-harness`; pinned SQL `12ba6da237b6a97eb92e7efc53bf81c34844bfa8`. Product source is unchanged by 2A. This includes Stage 1's authorized `generated` quoting fix. MySQL 8.4.6 uses the explicitly authorized temporary modes `ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` in the server and Laravel sessions. Production compatibility and strict-mode coverage remain TODOs.

This is a versioned planning inventory, not a claim that the planned business tests exist. Each row is a scenario family with named partitions to expand into parameterized cases in its owning chunk. IDs are stable; append cases rather than renumbering existing IDs. No business-family row is completed by a foundation smoke check.

Eligibility: **R** means runnable using current injection points and synthetic isolated services; **H** means deferred until an application isolation hook is delivered in Stage 3; **B** means blocked on a recorded non-hook prerequisite; **D** means a suspected defect/contract decision must be recorded when reproduced. R is a feasibility assessment, not an executed result. Later implementation may discover a narrower missing hook; record that change explicitly. No unsafe case may run merely because its initial eligibility is R.

Execution: backend owns PHP/SQL/HTTP, frontend owns components, and browser owns sandboxed tooling/future actual HMS journeys. All delivered foundation/tooling profiles passed two independent and cumulative cycles; see the [split verification report](suite-split-report.md). Foundation rows below are verified, while business families remain planned.

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

Proposed location: `tests/Integration/Tools/Sql/*Test.php`. Source: pinned `database/database/procedures/{sp_tool_*,fn_tool_in_maintenance_slot,fn_check_permission,sp_check_rfid,sp_get_details_from_rfid}.sql` and the referenced views. SQL functions live in the `procedures` directory too. Dependency loaders retain original SQL; no routine is rewritten for testing.

| ID | Partitions and observable assertions | Eligibility / dependencies | Status |
| --- | --- | --- | --- |
| SQL-01 | Status query: single/all/unknown tool, FREE/IN_USE/DISABLED and status reason, correct tool identity. | R; synthetic tools. | Planned |
| SQL-02 | Known/unknown/expired/lost RFID, inactive/non-member and missing general permission: reject with no usage or balance mutation. | R; cards, roles and referenced membership views. | Planned |
| SQL-03 | Restricted/unrestricted tool: ordinary user, inducted user, inductor and maintainer permission combinations. | R; real role/permission joins. | Planned |
| SQL-04 | Disabled tool and maintenance slot: maintainer exception, non-maintainer rejection, stored disabled status preserved during maintainer use. | R; SQL time uses bounded relative booking fixtures. | Planned |
| SQL-05 | Sign-on/off: usage start/completion, repeat calls, no active usage, tool/user isolation and returned status. | R; recreate after committing paths. | Planned |
| SQL-06 | Pledged credit: no award, positive/negative duration credits, partial consumption, expiry/time boundaries where applicable. | R; actual duration/transaction rows. | Planned |
| SQL-07 | Charge: zero/full/mixed rates, elapsed versus active time, integer rounding and persisted financial result. | R; synthetic account and balance fixtures added when needed. | Planned |
| SQL-08 | Maintenance charge exemption, insufficient credit and transaction-type override. | R; relevant status, permission and accounting rows. | Planned |
| SQL-09 | Repeated charging, invalid usage and SQL error/rollback: no double charge and exact committed/rolled-back outcomes. | R; independent reset for each case. | Planned |
| SQL-10 | Card induction: invalid/valid inductor and inductee, duplicates, grants/audit attribution and chargeable-tool free time. | R; persisted roles/audit rows, no external messaging. | Planned |
| SQL-11 | Booking retrieval/calendar/status helper functions: tool/time filtering, interval boundaries and maintenance detection. | R; PHP and SQL clocks treated separately. | Planned |
| SQL-12 | Relevant reporting views: usage/date/member summaries agree with source rows. | R for tools dependencies; broader legacy laser reporting B pending dependency/scope inventory. | Planned; legacy subset blocked |
| SQL-13 | Normal booking versus usage entitlement: record current reservation behavior separately from maintenance exceptions. | D; task question about advisory normal bookings remains open. | Planned reproduction/decision |

## Backend scenario families (2C)

Proposed locations: `tests/Unit/Tools/*Test.php` and `tests/Integration/Tools/*Test.php`. Sources: `app/HMS/Tools`, `Factories/Tools`, `Entities/Tools`, `Repositories/Tools`, `User/Permissions/RoleManager.php`, role listeners, statistics action and tool collector.

| ID | Partitions and observable assertions | Eligibility / dependencies | Status |
| --- | --- | --- | --- |
| PHP-01 | Tool factory defaults, default disabled/hidden/restricted states, prices and booking limits. | R; factory is independently constructible. | Planned |
| PHP-02 | Create tool: permission templates, existing permission reuse, role persistence and default status. | R; real/double repositories and explicitly expected event dispatch. | Planned |
| PHP-03 | Edit tool: allowed details/validation, duplicate names and persistence; internal rename/role identity stays an explicit decision. | R; rename D due existing unfinished behavior. | Planned |
| PHP-04 | Enable/disable: status/reason effects and reason presentation; no invented HTTP endpoint or notification behavior. | R; notify parameter unimplemented, not a completed feature. | Planned |
| PHP-05 | Remove tool: references from bookings/usage/roles and resulting constraints or cleanup. | R; actual schema constraints, ambiguous outcomes recorded. | Planned |
| PHP-06 | Grant/revoke user/inductor/maintainer: duplicate/missing operations, role persistence and authority split. | R; constructor-injected repositories. | Planned |
| PHP-07 | Real role-update listener attribution and retained user versus elevated membership-end roles. | R for local audit/member effects; unrelated outbound listeners guarded explicitly. | Planned |
| PHP-08 | Normal booking permission/limits: global/per-tool, restricted/unrestricted, outstanding count and no mutation on denial. | R; repository doubles plus selected persistence cases. | Planned |
| PHP-09 | Induction/maintenance booking permission combinations and maintenance exceptions. | R; deterministic users/roles/time. | Planned |
| PHP-10 | Invalid/equal/reversed/past times, maximum duration, exact boundaries and London DST/UTC conversion. | R; PHP clock helper. | Planned |
| PHP-11 | New booking clashes: all overlap shapes, adjacency and isolation between tools/users. | R; real query suite plus manager decisions. | Planned |
| PHP-12 | Edit booking: owner, tool mismatch, ongoing/unchanged values, permitted changes and persisted events. | R; update clash checking D. | Planned |
| PHP-13 | Cancel booking: removal, missing record behavior and stable event tool/booking identity. | R; real persistence and event contract. | Planned |
| PHP-14 | Current/next/future/day/week/month repository queries: inclusivity, cancellation and per-tool/user filtering. | R; Doctrine/custom Carbon mapping and real MySQL. | Planned |
| PHP-15 | API/broadcast serialization: IDs, types, field names, UTC/offset dates and before/after booking identity. | R for local serialization; mutable before/after alias D. | Planned |
| PHP-16 | Recent usage, free-time/member summaries and zero/no-usage cases. | R; real repositories/views. | Planned |
| PHP-17 | Statistics cache, current/prior month totals and role counts; metric labels/state values. | R; deterministic time/cache and real fixtures. | Planned |
| PHP-18 | Event/listener registration and dispatch contract; one isolated queue serialize/rehydrate round-trip. | R for registration/recording/serialization without listener execution; full MQTT listener execution H. | Planned; transport subset deferred |

## HTTP scenario families (2D)

Proposed location: `tests/Feature/Tools/*Test.php`. Sources: `routes/{web,api}.php`, tool/booking web and API controllers, `Api/SearchController`, middleware, guards and Doctrine binding.

| ID | Partitions and observable assertions | Eligibility / dependencies | Status |
| --- | --- | --- | --- |
| HTTP-01 | Anonymous/session/API authentication; verified/unverified email and enabled/disabled feature gates. | R; use supported helpers plus a small local credential/token flow. | Planned |
| HTTP-02 | Global/per-tool authorization, ownership and mismatched booking/tool route bindings: exact denial response and no writes/events. | R; actual middleware, no blanket bypass. | Planned |
| HTTP-03 | Tool list/detail/settings, hidden-list versus direct access and escaped status/reason. | R; deterministic content/meta; no external asset fetch required for response assertions. | Planned |
| HTTP-04 | Create/update/delete validation, duplicate/missing records and persistence/error responses. | R; existing routes only. | Planned |
| HTTP-05 | Booking API create/query/edit/cancel: status codes, validation, payload fields/timezones and persisted outcomes. | R; manager cases in 2C, explicitly guarded event dispatch. | Planned |
| HTTP-06 | Grant/revoke/free-time endpoints: authorized/denied/invalid/duplicate cases, audit and financial outcomes. | R; no live service transport. | Planned |
| HTTP-07 | Member search: authority, current-member restriction and response visibility. | R; shared member fixture extensions only as needed. | Planned |

## Frontend scenario families (2E)

Proposed location: standalone frontend test tooling alongside `tests/Environment/frontend`, extended without changing product package manifests. Sources: actual `resources/js/components/Tools/*.vue`, `MemberSearch.vue`, `MemberSelectTwo.vue` and required local wrappers.

| ID | Partitions and observable assertions | Eligibility / dependencies | Status |
| --- | --- | --- | --- |
| UI-01 | Calendar booking create/move/resize/cancel callbacks, payloads and ownership/permission controls. | R; actual component, local FullCalendar/Axios doubles. | Planned |
| UI-02 | Loading, request cancellation, validation/network errors and recovery without duplicate/stale state. | R; deterministic promise/clock control. | Planned |
| UI-03 | API/broadcast arrival order, duplicates and current-user count versus another user's bookings. | R; cross-user counter D. | Planned |
| UI-04 | Booking list filters, date/time presentation and component cleanup. | R; local Echo/calendar/plugin doubles. | Planned |
| UI-05 | Grant/member-selection/free-time/usage forms: selected identity, permissions, invalid input, request failure and recovery. | R; actual Vue behavior where implemented; Blade/HTTP paths owned by 2D/2F. | Planned |

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
| DEF-01 | Booking edits omit clash checking. | PHP-12; reproduce update overlap/self-exclusion, seek intended contract. |
| DEF-02 | BookingChanged holds original/current references to one mutable object. | PHP-15; reproduce serialized before/after identity. |
| DEF-03 | Broadcast handlers change current-user count for another user's booking. | UI-03; test cross-user events and arrival orders. |
| DEF-04 | Tool internal renaming does not coordinate permission/role names. | PHP-03; decide prohibition versus coordinated rename separately. |
| DEF-05 | Normal reservations are not exclusive operating entitlement in SQL sign-on. | SQL-13; confirm current behavior versus requested future enforcement. |
| COMPAT-01 | Timestamp migrations reject zero dates under strict mode. | Stage 1 report and task TODO; relaxed mode authorized, production mode unconfirmed. |

## Coverage and suite conventions

`tests/regression.xml` is the authoritative PHP coverage allowlist, fixed before business-test delivery. Core scope is `app/HMS/Tools` and `app/HMS/Factories/Tools`. The wider scope additionally includes tools entities/repositories, web/API controllers, tools events/listeners, statistics action/collector, selected role/audit/membership/authentication support and authorization middleware explicitly named in that file. Uncovered files remain included, even for the foundation-only draft. Additions/changes to this scope need a reason in the task; never narrow it to hide gaps.

PHPUnit's Clover and text reports measure executable PHP lines; PCOV does not supply branch coverage. No SQL, Blade or JS coverage is inferred from them. SQL scenario IDs above are the coverage ledger for 2B. Frontend instrumentation and actual component coverage arrive in 2E; browser journey evidence arrives in 2F. The original proposed final gates remain Stage 4 acceptance targets, not 2A claims.

| Suite | Current selection / convention | Status |
| --- | --- | --- |
| FoundationUnit | `tests/regression.xml`, `FoundationUnit`; PHPUnit 10, no network, guarded vendor entrypoint. | Implemented in 2A |
| FoundationDatabase | Same config, `FoundationDatabase`; real private MySQL, explicit reset strategies. | Implemented in 2A |
| SQL and backend integration | Proposed `tests/Integration/Tools`, resource-tagged; add explicit suite selections when delivered. | Planned 2B/2C |
| Backend unit | Proposed `tests/Unit/Tools`, no network. | Planned 2C |
| Routed feature | Proposed `tests/Feature/Tools`, full guarded Laravel bootstrap/real middleware. | Planned 2D |
| Component | Existing pinned Jest/Vue Test Utils; actual component behavior suites added later. | Tooling only; planned 2E |
| Browser | Pinned Playwright/Chromium with sandbox enabled. | Tooling only; actual journeys blocked for 2F |

Use PHPUnit here for the narrowly scoped foundation classes; Pest remains available from the existing lockfile for later domain suites. Do not alter the legacy broad PHPUnit bootstrap to bypass this suite's preflight. The cumulative `pr-check` action selects only delivered suites, records explicit asset deferrals and does not claim later chunks passed.
