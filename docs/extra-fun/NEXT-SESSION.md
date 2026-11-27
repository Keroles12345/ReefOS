# Extra Fun Integration — Next Session Handover

- **Stopped at:** P0.0 audit now includes an exact sanitized evidence request, read-only query set, client/owner decision register, and exit checklist. No actual DB/Hostinger evidence or new decisions were received; P0.0 remains blocked. No P0.1 work has started.
- **Readiness:** **NOT READY FOR IMPLEMENTATION**
- **Snapshot at handover:** `Keroles12345/ReefOS`, branch `main`, commit `02aeefa67340af267eb5f3eb434a150f7febf600`
- **Latest verified worktree:** no tracked changes; untracked `docs/extra-fun/` and `docs/implementation/`
- **Last updated:** 2026-11-27 22:17 +02:00

## Before doing anything

1. Read [IMPLEMENTATION-STATUS.md](./IMPLEMENTATION-STATUS.md), the latest entry in [IMPLEMENTATION-LOG.md](./IMPLEMENTATION-LOG.md), and this file.
2. Verify Git remote, branch, commit and `git status --short --branch`; preserve all untracked files.
3. Read [P0-0-pre-implementation-audit.md](./P0-0-pre-implementation-audit.md) and the existing [Extra Fun blueprint](../implementation/EXTRA_FUN_INTEGRATION_BLUEPRINT.md).
4. The blueprint is an existing untracked file and was intentionally not edited. Its repository label says `Keroles12345/systemMarinaX`; current Git remote is `Keroles12345/ReefOS`.

## Exact next task

Continue **P0.0 only**: receive and assess the evidence and decisions listed below and in section K of the audit. Do not start P0.1.

### Prerequisites

- Ask the authorized Hostinger owner/DBA to run only the read-only queries in audit sections K.1–K.4 in their already authenticated environment. Request sanitized schema metadata and aggregate counts only. **Do not request or receive passwords, connection strings, database credentials, private keys, API secrets, customer rows, booking IDs, or production exports.**
- Obtain Hostinger production DB product/version and settings plus confirmation of a disposable staging DB on a matching engine/version. If the engine is not MySQL/MariaDB, first record the product/version and obtain an engine-specific safe query set; do not assume.
- Obtain written Extra Fun answers for every question in audit K.5 (catalog, price matrix, schedule/timezone, participant requirements, capacity/resource/private-boat policy, pickup, cancellation/refund, payments, integration/communications).
- Obtain owner answers for every K.5 technical decision (legacy key/client/company/channel mapping, endpoint/idempotency contract, TTLs, retention, all booking writers and legacy reconciliation).
- Review actual `prices_company` columns/indexes and duplicate/null/mismatch counts before any pricing design is treated as deployable.
- Do not mark any K.6 checkbox complete from a plan, recommendation, local SQLite test configuration, or unverified Hostinger documentation.

### Relevant files and code locations

- Audit findings and table-by-table plan: [P0-0-pre-implementation-audit.md](./P0-0-pre-implementation-audit.md)
- Current target blueprint (read-only until separately approved): [EXTRA_FUN_INTEGRATION_BLUEPRINT.md](../implementation/EXTRA_FUN_INTEGRATION_BLUEPRINT.md)
- Current routes/auth: `routes/api.php`, `app/Http/Middleware/AuthenticateTenantApiKey.php`, `app/Http/Middleware/RequireTenantApiScope.php`
- API booking/pricing: `app/Http/Controllers/Api/WebsiteController.php`
- Staff booking/cancellation: `app/Http/Controllers/Staff/StaffOperations.php`, `app/Services/BookingLifecycleService.php`
- Pricing definition/call sites: `app/Models/Prices_company.php`, `database/migrations/2026_09_08_111011_create_prices_company_table.php`, `app/Http/Controllers/Admin/OperationResourcesController.php`
- Existing booking schema: migrations under `database/migrations/`, especially the tenant and booking lifecycle migrations.
- Shared API/schema coordination docs: `docs/API_CONTRACTS.md` and `docs/DATABASE.md`. Update only when a decision is approved and implementation is authorized.

### Commands/checks for the next task

- First: `git status --short --branch`, `git rev-parse HEAD`, and verify the remote without printing embedded credentials.
- Use audit section K for the DBA's read-only SQL and exact requested outputs. The assistant must not connect to a production database or run these queries itself.
- Do not run `php artisan migrate`, seeders, write queries, or any production DB command.
- Do not inspect `.env` or request credentials in chat. If safe DB evidence is unavailable, mark it **UNVERIFIED — REQUIRES CONFIRMATION** and remain blocked.
- Record each supplied artifact/decision as verified, unverified, blocked, or requires client decision. Update audit section K.6 only after actual evidence and approval. Append the new chronological work to `IMPLEMENTATION-LOG.md`; keep status and this handover consistent.
- No tests, migrations, or source changes are authorized by this handover. When P0.0 is eventually satisfied, stop and request separate implementation authorization before P0.1.

## Warnings / prohibited next steps

- Do not start P0.1 or implement any audit recommendation without explicit approval.
- Do not change the existing blueprint, source code, migrations, OpenAPI, configuration, or database as part of this handover.
- Do not assume the default SQLite config or in-memory SQLite tests represent Hostinger production.
- Do not treat capacity eight as proof of private-boat exclusivity; require client decision and interval locking design.
- Do not count both a converted reservation and its `operations` row as separate capacity consumers.
- Do not let old API or staff booking paths bypass slots once a trip becomes capacity-managed.
- Do not copy one legacy tenant API-key hash into multiple API clients or expose credential material.
- Do not treat website prices/cancellation pages as approved rules; public cancellation sources conflict.
- Tests were not run for the audit. Never report them as passed.
