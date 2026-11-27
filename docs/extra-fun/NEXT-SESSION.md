# Extra Fun Integration — Next Session Handover

- **Stopped at:** The first P0.1 security-containment increment is implemented and focused-tested locally. P0.0 evidence/approval gate remains **BLOCKED**; no schema-dependent work, migration, production access, or deployment occurred.
- **Last completed task:** Added exactly-one-active-company enforcement to company-sensitive legacy `/api/v1` routes and scoped company data, booking/payment access, and booking idempotency replay. Focused API suite: 18 tests / 235 assertions passed. Reviewer’s blocking inactive-company edge was fixed and regression-tested.
- **Exact next task:** Obtain authorized sanitized active non-production DB engine/schema evidence, including target/staging parity, before schema-dependent implementation.
- **Readiness:** This isolated code increment is implemented, tested, and independently reviewed. New API-client/channel schema, product catalog extensions, availability, quotes, and reservations are **NOT READY** pending their prerequisites.
- **Snapshot:** `Keroles12345/ReefOS`, branch `main`, `HEAD` / `origin/main` `01da4d9b26a54a1897cd1129dca0ac7d6c6d703b`.
- **Worktree:** Local `main` and `origin/main` were synchronized at verification. Worktree is now dirty with implementation and documentation changes listed below; no commit or push was created. Preserve all changes.
- **Last updated:** 2026-11-27 23:19 +02:00

## Before doing anything

1. Read [IMPLEMENTATION-STATUS.md](./IMPLEMENTATION-STATUS.md), the newest entry in [IMPLEMENTATION-LOG.md](./IMPLEMENTATION-LOG.md), and this handover.
2. Verify `git remote -v`, `git status --short --branch`, `git branch --show-current`, `git rev-parse HEAD`, `git rev-parse origin/main`, and `git log -5 --oneline`. Preserve the current uncommitted implementation and documentation.
3. Read [P0-0-pre-implementation-audit.md](./P0-0-pre-implementation-audit.md), the full [EXTRA_FUN_INTEGRATION_BLUEPRINT.md](../implementation/EXTRA_FUN_INTEGRATION_BLUEPRINT.md), [API_CONTRACTS.md](../API_CONTRACTS.md), and [DATABASE.md](../DATABASE.md).
4. Compare the handover snapshot with actual Git before updating any record. Do not rewrite historical audit evidence or log entries.

## Exact next task and prerequisites

### 0. Independent review outcomes — completed

- The Security Engineer found no evidence-based vulnerability in the reviewed scope and rated concurrent ownership changes as a medium residual risk; no database or concurrency test was performed.
- The Reviewer approved after the sole-inactive-company issue was fixed and regression-tested.
- QA confirmed the company-binding route/service coverage gaps are closed.
- Non-tested behavior remains: tenant-context-missing log branch and reason logging assertions; trip-list result after rebinding; a valid request with a mismatched `company_id`; behavioral enforcement of the booking-specific 10/minute threshold. Route listing confirms that threshold is registered, but a focused test attempt returned `429` before its expected validation response and was removed.

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

### 4. Resume implementation safely

- Keep P0.0 **BLOCKED** until audit exit criteria and approvals are satisfied. The user authorized engine-independent implementation; do not use that as permission to guess schema or business rules.
- Do not create/run migrations or implement schema-dependent API clients, channels, product metadata, participants, slots, quotes, or reservations until the active non-production DB engine/version and relevant schema are verified.
- Production remains strictly read-only; never inspect `.env`, request credentials, or connect to production.
- If authorized local implementation starts later, run focused tests on the confirmed target-matching disposable environment. At minimum test tenant/company/channel isolation, pricing snapshots, non-blocking quotes, expiration/idempotency, final-seat/resource concurrency, booking conversion, and legacy regression.
- This session ran `php artisan test tests/Feature/WebsiteApiTest.php`: 18 tests / 235 assertions passed on the test suite's in-memory SQLite. No active local or production DB was queried, and no migration was run.

## Relevant files

- [P0-0-pre-implementation-audit.md](./P0-0-pre-implementation-audit.md) — outstanding evidence and decision register, especially Sections K.1–K.6.
- [EXTRA_FUN_INTEGRATION_BLUEPRINT.md](../implementation/EXTRA_FUN_INTEGRATION_BLUEPRINT.md) — architecture and foundation proposal; Section 26 is preparation only.
- [API_CONTRACTS.md](../API_CONTRACTS.md) — verified existing routes and proposed client-bound API contract.
- [DATABASE.md](../DATABASE.md) — repository-derived schema facts and proposed migration dependencies.
- [IMPLEMENTATION-STATUS.md](./IMPLEMENTATION-STATUS.md) — current task state, blockers, and security finding status.
- `database/migrations/2026_09_08_111011_create_prices_company_table.php`
- `app/Models/Prices_company.php`
- `app/Http/Controllers/Api/WebsiteController.php`
- `app/Http/Controllers/Admin/OperationResourcesController.php`
- `routes/api.php`
- `app/Services/BookingLifecycleService.php`
- `tests/Feature/WebsiteApiTest.php`

## Warnings

- Source changes in this session are local and uncommitted; do not assume they are deployed or present on remote `main`.
- Do not commit directly to `main`. Obtain an approved non-main review path before committing/pushing.
- No migration, database, environment/configuration, OpenAPI, or deployment changes were made.
- Do not equate SQLite test configuration with production. SQLite does not prove target-engine row-lock/concurrency behavior.
- Do not add a `products` table; `trips` remains canonical.
- A quote never holds inventory; a reservation is a temporary capacity hold; booking conversion is atomic and one-time.
- No `remaining_capacity` source counter; capacity use comes from the shared reservation/allocation ledger.
- Do not count both a converted reservation and its `operations` row.
- No staff/API booking path may bypass capacity after a trip becomes capacity-managed.
- Do not clone one legacy tenant-key hash into multiple API clients or expose credential material.
- Advanced dive execution, medical/safety records, payment/refunds, and nonessential reporting remain deferred unless the client confirms they are required for first-site integration.
