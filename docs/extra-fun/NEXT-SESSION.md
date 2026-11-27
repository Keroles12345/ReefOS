# Extra Fun Integration — Next Session Handover

- **Stopped at:** Documentation-only implementation foundation is recorded in the blueprint and shared API/database coordination docs. A read-only Security Engineer review found six open design/documentation findings; no fixes were applied pending user direction. No source code or migration was created. P0.0 evidence/approval gate remains **BLOCKED**.
- **Last completed task:** Verified repository/runtime/source evidence and documented the target architecture, ERD, proposed migration order, bounded P0 roadmap, compatibility/rollback plan, and focused test plan.
- **Exact next task:** Ask the user to select the follow-up scope for the security findings; then address the selected documentation-only items before obtaining sanitized DB evidence and client/owner decisions.
- **Readiness:** Design package is prepared for review; implementation is **NOT READY** pending evidence and decisions.
- **Snapshot:** `Keroles12345/ReefOS`, branch `main`, `HEAD` / `origin/main` `d8ab9ba4b1a46818d21e88e8c5cd9273f497bc8b`.
- **Worktree:** Clean and synchronized at task start. Current session has documentation-only edits in the blueprint, API/database coordination docs, and three handover records; they are uncommitted. Preserve them.
- **Last updated:** 2026-11-27 22:57 +02:00

## Before doing anything

1. Read [IMPLEMENTATION-STATUS.md](./IMPLEMENTATION-STATUS.md), the newest entry in [IMPLEMENTATION-LOG.md](./IMPLEMENTATION-LOG.md), and this handover.
2. Verify `git remote -v`, `git status --short --branch`, `git branch --show-current`, `git rev-parse HEAD`, `git rev-parse origin/main`, and `git log -5 --oneline`. Preserve the existing uncommitted documentation.
3. Read [P0-0-pre-implementation-audit.md](./P0-0-pre-implementation-audit.md), the full [EXTRA_FUN_INTEGRATION_BLUEPRINT.md](../implementation/EXTRA_FUN_INTEGRATION_BLUEPRINT.md), [API_CONTRACTS.md](../API_CONTRACTS.md), and [DATABASE.md](../DATABASE.md).
4. Compare the handover snapshot with actual Git before updating any record. Do not rewrite historical audit evidence or log entries.

## Exact next task and prerequisites

### 0. Resolve the open security review findings

The Security Engineer identified one HIGH, four MEDIUM, and one LOW finding in the current proposal. No source-code vulnerability was tested or demonstrated; these are design/documentation issues. Ask the user whether to fix the highest-severity items, fix all findings, or record a summary. Do not silently select or implement a remediation scope.

### 1. Obtain database evidence safely

- Ask the authorized environment owner/DBA for the active **non-production local development** DB product/version and sanitized relevant schema output.
- Ask the authorized Hostinger owner/DBA for the production DB product/version/settings, read-only schema/index/FK metadata, migration ledger, and confirmation of disposable staging on a matching engine/version.
- Request only sanitized metadata and aggregate counts from audit sections K.1–K.4: final `prices_company` columns/FKs/indexes/nullability; migration state; tenant-null/cross-tenant/missing-reference counts; duplicate company/trip/currency price keys; and future operation/resource reconciliation totals.
- Do not inspect `.env`, request credentials/secrets, connect to production, or receive customer rows, booking identifiers, exports, or raw API keys. If the environment cannot be proven non-production, do not connect.
- The locally verified PHP drivers (`mysql`, `sqlite`) and in-memory SQLite `3.53.4` are not evidence of the active local DB, Hostinger engine, or target locking behavior.

### 2. Resolve and document `prices_company`

- Repository-level fact: migrations, query-builder callers, and tests use `id_company`, `id_trip`, `id_currency`, and `price_adult/child/infant`.
- The Eloquent model fillable names and default relation keys differ. No direct model caller was found.
- Compare sanitized live metadata and duplicate/tenant-integrity counts before changing model/schema or adding a uniqueness constraint. Do not rename columns based only on the model.

### 3. Obtain written owner/client decisions

Use the decision register in blueprint Section 24 and audit Section K.5. At minimum confirm:

- initial tenant/company/channel and currency mapping, legacy API integrations, key rotation/deprecation and scopes;
- active catalog/variants, approved prices/currencies/taxes and pricing rules;
- schedules, timezone/DST, capacities, seat units, shared-vs-exclusive boat policy, instructor limits and resource overlap/buffers;
- participant fields, age/qualification requirements and privacy/retention;
- equipment stock and sharing, pickup rules, cancellation/refund policy, payment provider/deposit;
- quote TTL, reservation-hold TTL/extension, idempotency/replay, and all current booking writers.

Do not treat website content or proposed defaults as approval.

### 4. Resume only after prerequisites

- Update the architecture/docs with verified evidence and decisions; keep unapproved policies marked **Requires Client Decision**.
- Do not create migrations or change application code until the actual active local DB engine/version, relevant schema, assumptions, and focused tests are recorded. Production remains read-only.
- If authorized local implementation starts later, run focused tests on the confirmed target-matching disposable environment. At minimum test tenant/company/channel isolation, pricing snapshots, non-blocking quotes, expiration/idempotency, final-seat/resource concurrency, booking conversion, and legacy regression.
- No application tests or migrations were run. The only DB query was `SELECT sqlite_version()` against a temporary in-memory SQLite connection; no active local or production DB was queried. Never report tests as passed.

## Relevant files

- [P0-0-pre-implementation-audit.md](./P0-0-pre-implementation-audit.md) — outstanding evidence and decision register, especially Sections K.1–K.6.
- [EXTRA_FUN_INTEGRATION_BLUEPRINT.md](../implementation/EXTRA_FUN_INTEGRATION_BLUEPRINT.md) — architecture and foundation proposal; Section 26 is preparation only.
- [API_CONTRACTS.md](../API_CONTRACTS.md) — verified existing routes and proposed client-bound API contract.
- [DATABASE.md](../DATABASE.md) — repository-derived schema facts and proposed migration dependencies.
- `database/migrations/2026_09_08_111011_create_prices_company_table.php`
- `app/Models/Prices_company.php`
- `app/Http/Controllers/Api/WebsiteController.php`
- `app/Http/Controllers/Admin/OperationResourcesController.php`
- `routes/api.php`
- `app/Services/BookingLifecycleService.php`
- `tests/Feature/WebsiteApiTest.php`

## Warnings

- No application source, migration, database, environment/configuration, OpenAPI, or deployment changes are authorized by this handover.
- Do not equate SQLite test configuration with production. SQLite does not prove target-engine row-lock/concurrency behavior.
- Do not add a `products` table; `trips` remains canonical.
- A quote never holds inventory; a reservation is a temporary capacity hold; booking conversion is atomic and one-time.
- No `remaining_capacity` source counter; capacity use comes from the shared reservation/allocation ledger.
- Do not count both a converted reservation and its `operations` row.
- No staff/API booking path may bypass capacity after a trip becomes capacity-managed.
- Do not clone one legacy tenant-key hash into multiple API clients or expose credential material.
- Advanced dive execution, medical/safety records, payment/refunds, and nonessential reporting remain deferred unless the client confirms they are required for first-site integration.
