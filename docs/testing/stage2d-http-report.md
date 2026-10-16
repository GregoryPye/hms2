# Stage 2D routed HTTP regression delivery

Status: implemented and independently/cumulatively exercised; required contract findings remain open and the gate remains red. Baseline `f9230ad4` on `tools-test-harness`, pinned SQL `12ba6da237b6a97eb92e7efc53bf81c34844bfa8`. Product code, views, routes, migrations and dependency manifests remain unchanged.

## Boundaries and execution

Five named suites in `tests/regression.xml` exercise the real application HTTP kernel in-process, registered routes, production middleware, Doctrine binding/repositories and disposable MySQL. Every case recreates the migrated schema and pinned routines. No web server, browser, remote HMS instance, production data, scheduler or general worker runs.

| Suite | Scenario families | Boundary |
| --- | --- | --- |
| HttpAuthentication | HTTP-01/02 | Anonymous/session/Passport guards, local username/email/password login and session continuity/logout, real signed bearer token and revocation, verified-email and tools-feature behavior |
| HttpBooking | HTTP-02/05 | Actual nested resource binding, permission/ownership/tool mismatch, validation, typed create/query/edit/cancel JSON and persisted outcomes, overlap and advertised show route |
| HttpToolPages | HTTP-02/03/04 | Actual Blade/layout responses, visibility/escaping/settings/calendar, tool CRUD/validation and usage authority |
| HttpToolAccess | HTTP-02/06 | Grant-list authority, grant/revoke audit/idempotency and signed free-time persistence |
| HttpMemberSearch | HTTP-07 | Real search repository/pagination, global versus tools-only authority, forced current-member restriction, account/query filters and response-field visibility |

`HttpTestCase` boots the request application after fixtures/permission definitions exist, so the real ACL provider registers Gate abilities from this case's database. It grants real persisted permissions/roles and uses supported `Passport::actingAs`/session-user helpers for the broader matrix. No Gate callbacks are replaced and middleware is not globally disabled. Local credential requests use the real Doctrine password store. A bearer case uses a synthetic personal-access client, actual Passport token signing and actual guarded HTTP reads/revocation; it is not evidence of an OAuth password-grant HTTP exchange or external identity provider.

Temporary RSA keys exist only in the isolated PHP process/config, generated anew for each suite process, never in source, reports or a production location. Session continuity passes actual encrypted response cookies into subsequent kernel requests. Standard Laravel testing-mode CSRF behavior remains; these tests do not claim browser CSRF enforcement. Cookie/browser policy remains a later integration concern.

Actual templates and layouts render. A strict test-only Mix resolver returns only the five expected local static URL paths: this substitutes asset-manifest lookup, not pages, controllers or middleware. No compiled/private Font Awesome assets are built, read or fetched. HTTP HTML assertions do not execute linked scripts/fonts or prove the later browser journeys. Preparation generates `/app/version` from the reviewed commit instead of copying `.git` or running deployment scripts. Synthetic metadata updates existing migration-owned keys; test content blocks supply only minimal page/logo content.

Production event registration is retained. Selected booking events are recorded, and role event types use the previously documented real synchronous RoleUpdateLogger wiring with outward/queued handlers removed only for those named events. Assertions check audit actor/target/role rows. Strict transport doubles and independent network isolation remain mandatory; nothing invokes real mail, payments, MQTT, directory or Discord clients.

## Commands

```sh
python3 tests/Environment/run.py prepare --profile backend
python3 tests/Environment/run.py pr-check --profile backend --suite http --cycles 2 --output /tmp/hms-2d-http
# Default backend also includes completed foundation, SQL and 2C suites:
python3 tests/Environment/run.py pr-check --profile backend --cycles 2 --output /tmp/hms-2d-cumulative
```

The HTTP-only selector retains all safety/tooling/database prerequisites and database foundation. Partial selections cannot satisfy a complete three-profile aggregate. Frontend/browser remain deferred following the existing backend-only verification scope. JUnit, Clover, logs and machine-readable counts/coverage are generated under the selected output directory.

## Verification, findings and coverage

Focused `/tmp/hms-2d-http/report.json` completed two cycles with exact reverse case order within each named suite and identical outcomes/assertion counts. It exits 1/blocked, with 44 passing check groups and four blocked groups (booking and tool-access once per cycle). All safety/prerequisite/cleanup checks pass. No cases are skipped.

| HTTP suite | Cases | Pass | Assertion failures | Test errors | Assertions |
| --- | ---: | ---: | ---: | ---: | ---: |
| HttpAuthentication | 11 | 11 | 0 | 0 | 119 |
| HttpBooking | 21 | 19 | 2 | 0 | 272 |
| HttpToolPages | 16 | 16 | 0 | 0 | 167 |
| HttpToolAccess | 11 | 10 | 1 | 0 | 144 |
| HttpMemberSearch | 5 | 5 | 0 | 0 | 61 |
| **Stage 2D per cycle** | **64** | **61** | **3** | **0** | **763** |

Database foundation adds three passing cases / 29 assertions per focused cycle. Application 500 responses are deliberately captured as failed HTTP contract assertions; they are not test-harness errors. Focused-only union line coverage is 199/227 core (87.67%) and 812/1,344 full scope (60.42%), retaining all 47 files. This partial-scope figure is distinct from cumulative coverage below.

Focused-run peak PHP allocation was 120.5 MB (booking), with tool pages at most 110.5 MB and access/audit 94.5 MB, below the unchanged 128 MB PHP limit. Sampled host filesystem peak/minimum free: 26.17/3.67 GiB; incremental peak 2.74 GiB; observed aggregate container memory peak about 1,058 MiB over 100 samples. Complete cleanup passed. Final cumulative results are reconciled below. Preparatory trials are not accepted as final evidence. A migration-owned metadata-key duplication in the first fixture was corrected by updating disposable rows. Further test-only corrections supplied temporary Passport keys, a valid public password hash with a foundation regression assertion, the version artifact, and application-reference teardown under the existing 128 MB PHP limit. Teardown alone was insufficient for the original 27-case tool group; page/CRUD and access/audit groups now run in separate PHP processes with the same limit, preserving complete required-check accounting. The app uses an errors-array JSON authentication envelope, now asserted explicitly. No product behavior was changed to resolve setup assumptions.


## Contract findings

- DEF-01 is now reproduced through the actual booking PATCH route: an overlapping edit is accepted instead of rejected. Its existing 2C contract remains red too.
- DEF-12: the resource declaration registers GET booking show, but its controller has no show method; a valid authorized request returns 500. The failing resource contract expects the advertised read to work; implementing it versus removing the declared route needs an explicit product decision.
- DEF-13: omitting free-time duration fields passes nullable validation, then unconditional array access returns 500. The failing contract expects validation rejection without usage writes; zero/omitted/negative-duration semantics remain a recorded question.

The test draft includes no expected-failure wrappers, skips or product fixes. API creation without general tools.book returns 403 and preserves booking state/events, which distinguishes the routed boundary from DEF-09's direct-manager ambiguity. Existing web verified-email versus API omission, feature-disabled redirects, and edit/cancel mismatch status differences are documented observations, not inferred new policy.


## Coverage interpretation and explicit gaps

The selected web/API tools and member-search controllers cover 258/274 executable lines (94.16%): ToolController 159/163, API BookingController 53/53, web BookingController 10/10, SearchController 36/48. This includes failing contracts. A missing controller method has no executable lines, so the broken advertised show route is caught by the route matrix even though the API controller's existing lines reach 100% coverage.

The search controller's invitation branch, the password store's add/remove/change/exists operations, Passport password-grant credential exchange, and AuthorizeAll (not attached to the inspected tools routes) remain uncovered. They are not exercised through invented tools endpoints to improve a number. Large-result search pagination, all possible input combinations, browser cookie/CSRF behavior, private/compiled assets, live queue/broadcast delivery and HOOK-01 MQTT execution are not claimed. Referenced-tool deletion atomicity remains the 2C manager reproduction (DEF-11), rather than another HTTP error-status assertion. Full fixed-allowlist gaps and original final coverage gates remain visible in cumulative reporting; PCOV supplies line, not branch, coverage.


## Final cumulative verification

`/tmp/hms-2d-cumulative/report.json` completed both fresh backend cycles on the final unchanged product/tooling snapshot. Every one of the 16 named regression suites has exact reverse case order in cycle two and identical per-case outcomes/assertion counts. No required suite/check is missing or duplicated. The command correctly exits **1/blocked**: **54 passing check groups / 14 blocked**, all from the documented business contracts/legacy SQL findings. All safety, tooling, schema, foundation and cleanup checks pass; all seven host controller tests pass. No frontend/browser profile or complete three-profile aggregate is newly claimed.

| Layer, per cycle | Cases | Pass | Assertion failures | Test errors | Assertions |
| --- | ---: | ---: | ---: | ---: | ---: |
| Foundation | 6 | 6 | 0 | 0 | 46 |
| Stage 2B SQL | 80 | 73 | 4 | 3 | 569 |
| Stage 2C backend | 100 | 94 | 6 | 0 | 451 |
| Stage 2D HTTP | 64 | 61 | 3 | 0 | 763 |
| **Cumulative** | **250** | **234** | **13** | **3** | **1,829** |

The additional foundation assertion verifies the public fixture password with the real hash verifier. Existing DEF-01/02/04/06/07/08/09/10/11, LEGACY-01 and COMPAT-02 findings remain visible; DEF-12/13 add HTTP failures. COMPAT-03's statistics deprecation remains displayed without inventing a new deprecation failure policy. No expected-failure wrapper, product fix or waiver makes the gate green.

Final coverage unions 32 current Clover artifacts, retaining all 47 files: **224/227 core lines (98.68%)** and **1041/1344 full-scope lines (77.46%)**. Repeated cycles are not added together; failing-test execution is included and is not correctness evidence. The wider 85% final target is still unmet.

Sampled filesystem peak/minimum free: 26.17/3.67 GiB; incremental peak 2.74 GiB. Observed aggregate container memory peak about 1100 MiB over 259 samples. These are sampled observations on the unchanged managed `vfs` host, not resource guarantees.

Product digest remains `65180871f036a42614559a7b206c573ea5bff4df482318776d5e624e7e7906c8`; current tooling digest `250f02d5f88bff6202c7fc976a0ae658738a6af1b1b958f4431715de4b6cf175` matches both final reports. Final inspection confirms no test containers or private networks, with registered backend preparation caches retained. Product/configuration/routes/views/migrations/original SQL/dependencies have a clean diff; `git diff --check` passes. Artifact scans found no emitted private RSA key or bearer header. No workflow activation, commit or push is included.
