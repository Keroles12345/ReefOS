# Extra Fun Integration — P0.0 Pre-Implementation Audit

**Audit date:** 2026-11-27

**Mode:** Read-only audit. No application, migration, configuration, OpenAPI, or database changes were made.
**Decision:** **NOT READY FOR P0.1.** Resolve the blockers in this report and obtain explicit owner/client approval before implementation.

## Evidence labels

- **VERIFIED:** directly observed in repository source, tests, configuration templates, or command output.
- **DERIVED:** expected schema/behavior when the checked-in migrations and code are applied; not live-database introspection.
- **UNVERIFIED — REQUIRES CONFIRMATION:** not established by safe repository inspection.
- **CLIENT DECISION REQUIRED:** business policy must be approved by Extra Fun.

## A. Repository and Environment Confirmation

| Item | Finding |
|---|---|
| Repository | **VERIFIED:** Git remote is `https://github.com/Keroles12345/ReefOS.git`; this matches the expected ReefOS repository. The local directory name `SystemMarinaX - Copy` is not the Git repository identity. The blueprint's `systemMarinaX` wording is a stale/conflicting identifier, not the current remote. |
| Root / branch / commit | **VERIFIED:** `D:\laravel\SystemMarinaX - Copy`, branch `main`, commit `02aeefa67340af267eb5f3eb434a150f7febf600`. |
| Initial working tree | **VERIFIED:** clean relative to `origin/main`, with an existing untracked `docs/implementation/` directory. That directory and its blueprint were left untouched. |
| Framework requirement / runtime | **VERIFIED:** `composer.json` requires PHP `^8.3` and Laravel `^13.0`; actual CLI is PHP `8.5.10`; `php artisan --version` reports Laravel `13.43.0`. CI config uses PHP 8.3. See [composer.json](../../composer.json) and [tests.yml](../../.github/workflows/tests.yml). |
| Local PDO drivers | **VERIFIED:** local PHP reports `mysql, sqlite`. This identifies available drivers only, not a connected database or production server. |
| Database configuration | **VERIFIED:** Laravel defaults to SQLite if `DB_CONNECTION` is unset; `.env.example` also says SQLite. Laravel config supports SQLite, MySQL, MariaDB, PostgreSQL, and SQL Server. PHPUnit explicitly uses in-memory SQLite. See [database.php](../../config/database.php), [.env.example](../../.env.example), and [phpunit.xml](../../phpunit.xml). |
| Actual database engine/version | **UNVERIFIED — REQUIRES CONFIRMATION:** no live DB metadata query was run. The active `.env`, connection credentials, database file contents, and production DB were not inspected. A deployed Hostinger engine/version cannot be inferred from local defaults. |
| Deployment structure | **VERIFIED:** README documents configuration and queue setup; GitHub Actions runs tests and Pint, not deployment. No Hostinger deployment manifest or production DB version was found in the reviewed repository evidence. See [README.md](../../README.md) and [tests.yml](../../.github/workflows/tests.yml). |
| Tests | Existing tests were read, **not run**. This audit did not connect to a DB, run migrations, or perform production operations. |

## B. Six-Point Technical Review

### 1. Private boat exclusivity

**Current implementation status — VERIFIED:** There is no slot inventory or private-boat exclusivity model. Existing `boats` have capacity, but `operation_assignments` stores a day-level `boat`/`vehicle` discriminator and untyped `resource_id`; it is not an interval reservation ledger. The proposed blueprint has participant capacity and typed slot resources, but does not define a per-slot shared-versus-exclusive allocation rule or an exclusive booking claim.

Relevant evidence: [operation_assignments migration](../../database/migrations/2026_09_21_130000_create_operation_assignments_table.php), [blueprint resource design](../implementation/EXTRA_FUN_INTEGRATION_BLUEPRINT.md#L657), and [blueprint private-boat observation](../implementation/EXTRA_FUN_INTEGRATION_BLUEPRINT.md#L262).

**Risk:** A capacity of eight only limits the sum of passengers. It does not prevent two customers each booking part of the same boat at the same time. Nor does a unique `(boat_id, slot_id)` constraint prevent two *different, overlapping* slots from using that boat. Half-day/full-day schedules can overlap even when their start times differ.

**Recommendation:** Make allocation policy explicit for every bookable boat slot:

- `shared_capacity`: multiple reservations may share the slot; total participants cannot exceed the minimum of the slot limit, boat capacity, and applicable crew limit.
- `exclusive_resource`: one live reservation owns the entire boat/time interval, regardless of party size; it conflicts with both another exclusive claim and any shared use of that boat in an overlapping interval.

Represent interval ownership with typed resource FKs and a durable resource-claim/reservation ledger. For each boat scheduling or reservation write, use one DB transaction, lock the canonical boat/resource row, query overlapping half-open intervals `[start,end)`, reject conflicts, and write the claim before releasing the lock. Add unique constraints for exact resource/slot claims and one active exclusive claim per discrete slot where the chosen schema supports it. A normal unique or check constraint cannot express arbitrary interval overlap portably on MySQL/MariaDB; a PostgreSQL exclusion constraint is an optional engine-specific defense, not a portable assumption. Every slot admin, staff booking, API booking, reassignment, expiry, and cancellation path must use the same locking protocol. Include approved turnaround buffers in the interval.

Existing boats are tenant-owned and `boats.company_id` was removed. Therefore, a channel's company must not automatically be allowed to allocate every tenant boat: either explicitly map usable boats to companies/channels or approve shared tenant-wide boat inventory with authorization and conflict rules.

**CLIENT DECISION REQUIRED:** Confirm whether each private-boat product is always exclusive, optionally exclusive, or shareable; whether half/full-day options reserve the same boat intervals; maximum party size (the stated eight-person limit is a client-provided requirement, not a verified repository fact); schedule/buffer rules; fixed charter price versus any passenger-dependent charge; and whether boats are assigned per company or shared tenant-wide.

**Blocks implementation:** **Yes** for private-boat availability, slot/resource schema, and acceptance tests. Do not configure production boat slots before this decision.

### 2. Capacity accounting invariants

**Current implementation status — VERIFIED:** No availability slots or temporary reservation ledger exist. The public booking endpoint calculates a price and inserts an `operations` row without capacity locks. The staff workflow also creates/updates operations outside a shared inventory service. `BookingLifecycleService` locks the operation row for status changes, but knows nothing about inventory. See [WebsiteController.php](../../app/Http/Controllers/Api/WebsiteController.php#L87), [StaffOperations.php](../../app/Http/Controllers/Staff/StaffOperations.php#L121), and [BookingLifecycleService.php](../../app/Services/BookingLifecycleService.php#L20).

**Required accounting decision:** Use reservation items as the **single capacity ledger**. For a slot, count each reservation item once if its parent is either (a) `held` and its expiration is later than the authoritative clock, or (b) `converted` and not released by an authorized cancellation. Count neither expired nor released rows. Do not separately add `operations` to that sum. Every confirmed operation must point to exactly one converted reservation, and every reservation can point to at most one operation. Legacy bookings assigned to a slot must first be reconciled into converted reservation items; otherwise that slot is not publishable.

| Invariant | Audit result / required design |
|---|---|
| 1. Active, unexpired holds consume capacity | **Blueprint recommendation is sound.** Evaluate expiry at reservation and booking conversion time under the slot lock. Availability must stop counting an expired hold immediately, even if a cleanup job has not marked it expired. |
| 2. Converted reservations consume capacity | Count the converted reservation item once while the booking remains capacity-consuming. The operation is the commercial record, not a second capacity row. |
| 3. Expired holds do not consume capacity | Use one trusted UTC clock source for creation, comparison, and conversion. Housekeeping only updates status; it is not part of correctness. |
| 4. Released holds do not consume capacity | `released` must be a terminal capacity state and release must be idempotent. |
| 5. Cancellation releases only after an authorized lifecycle transition | Update operation status/history and reservation release in the **same transaction**. Current lifecycle has `pending/confirmed → cancelled` and `paid → refunded`, but refund is not defined as cancellation. Decide whether refunded bookings retain or release inventory; do not infer it from payment status. |
| 6. Operation and converted reservation are never counted twice | Unique nullable `operations.booking_reservation_id`; capacity queries read reservation items only. Legacy operations are represented once as converted ledger rows. |
| 7. All booking paths obey the same rules | Include API, staff-created bookings, any admin/resource workflow, cancellation, reassignment, and any future integration. The current `/staff/operation/create/{trip}` creates an operation before the later save step; that draft flow must be treated explicitly so drafts do not consume capacity and completed capacity-managed bookings cannot bypass slots. |

**Concurrency protocol:** In one transaction, lock all requested slots in stable ascending ID order, recheck current holds/bookings, then insert all reservation and resource claim rows. Lock the reservation again for booking conversion, check it has not expired/released, and create the operation plus conversion atomically. Use a client/endpoint-scoped idempotency key and unique DB constraints for replay races. A multi-slot failure rolls back every slot. A cancellation transition locks the same reservation/resource claims and releases them atomically.

**Risk:** The blueprint mentions slot locks but not a completely shared path across the existing staff and API creation flows, nor does it fully specify converted-reservation release status coupled to `BookingLifecycleService`. SQLite test locks cannot validate the production race behavior.

**Blocks implementation:** **Yes.** This is a P0 safety invariant. Implementing only the new website endpoint would leave internal staff operations able to overbook.

### 3. Database engine and version

**Verified facts:**

- `config/database.php` defaults to SQLite and supports multiple drivers; its SQLite transaction mode is `DEFERRED`.
- PHPUnit uses `sqlite` with `:memory:`; current tests therefore do not demonstrate production MySQL/MariaDB locking.
- A checked-in migration uses raw non-SQLite `ALTER TABLE ... MODIFY ... ENUM`, which is MySQL/MariaDB-specific; configured support for PostgreSQL/SQL Server is not evidence that the full migration history supports them.
- `lockForUpdate()` is used for payment/lifecycle records, but no current reservation/concurrency implementation exists.
- README says deployed `DB_CONNECTION` is typically MySQL or SQLite; no Hostinger DB engine/version is recorded.

Evidence: [database.php](../../config/database.php#L20), [phpunit.xml](../../phpunit.xml#L26), [ENUM migration](../../database/migrations/2026_09_12_132639_add_expired_to_tickets_status.php#L12), and [README configuration](../../README.md#L103).

**UNVERIFIED — REQUIRES CONFIRMATION:** Actual deployed engine/version, storage engine, isolation level, session timezone, SQL mode, FK/check enforcement, and Hostinger plan/version. The audit deliberately did not read `.env`, connect with credentials, or query a production database.

**Compatibility review:**

- **Foreign keys:** Existing migrations use them. New tenant-consistent references should use composite `(tenant_id, parent_id)` keys where the target engine and legacy indexes permit; otherwise transactional service validation is mandatory. Audit existing nulls/duplicates before adding `NOT NULL` or composite indexes.
- **Composite foreign keys:** Supported by common target engines, but require exact matching parent indexes, column order/types, and migration rehearsal. SQLite support does not prove MySQL/MariaDB deployment behavior.
- **Check constraints:** Useful for positive quantities, valid intervals, and exactly-one typed resource, but enforcement depends on engine/version. Add application validation regardless; verify the target version enforces checks.
- **Transactions / row locks:** MySQL/MariaDB InnoDB and PostgreSQL have row-locking semantics suitable for a serialized slot/resource transaction. SQLite has database-level writer serialization and Laravel's SQLite grammar does not provide equivalent row-level `FOR UPDATE`; it is not a concurrency acceptance environment.
- **Unique constraints:** Use for quote-to-reservation and operation-to-reservation one-to-one relationships and idempotency. Nullable unique behavior and actual existing duplicates must be tested on the target engine.
- **Indexes:** Index tenant/product/start/status, reservation status/expiry, and resource ID/start/end. Validate with realistic target-engine query plans; do not use an unindexed conflict scan under a parent lock.
- **Hostinger:** **UNVERIFIED — REQUIRES CONFIRMATION.** Before migration approval, obtain the production DB product/version and engine (e.g. InnoDB), isolation/session timezone, and a disposable staging environment matching those values. Then test composite FKs, check constraints, transactional DDL/rollback limitations, `FOR UPDATE`, and simultaneous final-seat/boat-claim races on that engine.

**Blocks implementation:** **Yes** for schema migration and any claim of safe concurrent booking. Until the production engine/version is supplied, locking, checks, and Hostinger compatibility remain unverified.

### 4. Legacy API security and tenant isolation

**Current authentication — VERIFIED:** The API key is a single tenant-level bearer key. Middleware finds `tenants.api_key_prefix`, verifies a SHA-256 hash with `hash_equals`, checks expiry and tenant status, initializes tenant context, and loads scopes. It does not resolve a company, sales channel, or currency binding. The plaintext key is not stored in the inspected implementation. See [AuthenticateTenantApiKey.php](../../app/Http/Middleware/AuthenticateTenantApiKey.php#L17) and [TenantApiScopes.php](../../app/Support/TenantApiScopes.php).

**Existing API routes and access:**

| Route | Current gate | Finding |
|---|---|---|
| `GET /api/documentation`, `GET /api/openapi.yaml` | Public | Documentation/contract are intentionally unauthenticated. |
| `POST /api/webhooks/payments/{provider}` | IP throttle; provider HMAC signature in service | Not tenant-key authenticated; secret is required, request signature is verified, and unconfigured verification returns 503. See [PaymentWebhookService.php](../../app/Services/PaymentWebhookService.php#L22). |
| `GET /api/v1/trips`, `GET /api/v1/trips/{trip}` | Tenant API key + `trips:read` | Tenant-scoped trips, but trip list joins and returns prices for every active company in that tenant. |
| `GET /api/v1/companies` | Tenant API key + `companies:read` | Returns every active tenant company, including email/phone. |
| `GET /api/v1/currencies` | Tenant API key + `currencies:read` | Returns all active global/shared currencies, not a channel-bound currency. |
| `POST /api/v1/bookings` | Tenant key + `bookings:create`; `company_id` and `currency_id` are client-supplied; idempotency optional | Validates company belongs to the tenant, not to an integration/client. A tenant key can create bookings under any active company in that tenant and choose any active shared currency. |
| `GET /api/v1/bookings/{booking}` | Tenant key + `bookings:read` | Tenant-scoped opaque booking ID, but no company/client binding; a key with read scope can retrieve any tenant booking if its ID is known. |
| `PATCH /api/v1/bookings/{booking}/status` | Tenant key + `bookings:manage` | Same tenant-wide resource scope. |
| `POST /api/v1/bookings/{booking}/payments` | Tenant key + `payments:create` | Same tenant-wide booking scope; provider currently unconfigured. |
| `POST /api/v1/payments/{payment}/refund` | Tenant key + `payments:refund` | Tenant-scoped payment service; provider currently unconfigured. |

Routes are in [routes/api.php](../../routes/api.php#L7); handlers and company/currency validation are in [WebsiteController.php](../../app/Http/Controllers/Api/WebsiteController.php#L30). The `TenantDatabase` guard and `BelongsToTenant` scope provide useful tenant isolation, but neither establishes company authorization. `currencies` is global/shared.

Adjacent public path: [the web ticket route](../../routes/web.php#L97) uses a separate opaque ticket token and [PublicTicketTenantContext](../../app/Http/Middleware/PublicTicketTenantContext.php#L11), not the tenant API key. Keep it a narrowly scoped ticket capability; it is not an API-client/company authorization mechanism.

**Key-without-company conclusion:** **Yes.** Every current key is bound only to its tenant and can access multiple companies in that tenant through both reads and writes. A key lacking `bookings:create` is still not safe for reads: it can see all tenant company contacts, all companies' trip prices, and any known tenant booking ID when granted the corresponding read scopes. This is cross-company exposure, not evidence of cross-tenant exposure.

**Scopes and rate limits:** Existing defaults include trips, companies, currencies, booking create and booking read; other scopes are per route. Group limit is 60/minute and booking limit 10/minute, configured through Laravel throttle middleware and keyed by IP for these unauthenticated API-key requests, not by API client. New clients need rate limits keyed to client (optionally also IP) and independent revocation. A CORS origin allow-list is not authentication; browser code must never receive the secret.

**Backward-compatible target policy:**

1. Inventory tenants, integrations, scopes, last use, and tenant companies without logging or copying raw credentials.
2. Explicitly map every retained integration to one tenant, one company, and authorized channel(s).
3. For an explicitly verified single-company/single-integration tenant, an additive migration may move the existing prefix/hash to one API-client row, or issue a replacement secret. Verify behavior before deprecating the old path.
4. For a tenant with multiple companies/integrations, do not clone one hash into multiple clients. Issue distinct secrets and migrate each integration; no broad multi-company fallback.
5. Restrict legacy read and write responses to the mapped company/channel, including prices, company, currency, bookings, status, payments, and refunds. Deny unmapped keys rather than assuming read-only is harmless.
6. Preserve old endpoint payloads only for mapped clients and non-capacity-managed trips. Once a trip is capacity-managed, all API and staff booking paths must require the reservation flow.
7. Keep a time-bounded, observed overlap; revoke old credentials only after confirmed cutover. Retain historical records and do not expose secrets in logs/reports.

**Blueprint gap:** The blueprint recognizes the company binding requirement, but its contract puts the new reservation flow on the existing `POST /api/v1/bookings` route while also preserving the legacy request. It must define a discriminated request/validation path before legacy required-field validation, or choose a separate additive conversion endpoint. Do not let the existing schema reject valid reservation requests or accidentally take the legacy price path.

**Blocks implementation:** **Yes.** Security isolation and legacy read behavior must be fixed/approved before P0.1. Treat the observed cross-company read/write capability as **HIGH** risk.

### 5. Timezone strategy

**Current implementation status — VERIFIED:** Application timezone is UTC. Trips store `time_trip`; `operations` stores `trip_date` as DATE and `trip_time`/`time_pickup` as TIME; trip-hotel mappings store `pickup_time` as TIME. The API validates `trip_date` against `today()` and writes the trip's configured clock time. No tenant timezone, slot timezone, UTC offset, or DST policy was found in the reviewed schema. See [app.php](../../config/app.php#L62), [operations migration](../../database/migrations/2026_09_08_104322_create_operations_table.php#L47), [trips_hotels migration](../../database/migrations/2026_09_08_104259_create_trips_hotels_table.php#L25), and [WebsiteController.php](../../app/Http/Controllers/Api/WebsiteController.php#L129).

**Risks:** Comparing a local activity date to UTC `today()` can accept/reject bookings around midnight incorrectly. Naive date/time columns do not identify their timezone and cannot disambiguate repeated/nonexistent wall-clock times during DST. Resource overlap checks also cannot be correct if different servers interpret local times differently.

**Recommendation:**

- Establish an approved IANA timezone default per tenant; snapshot the IANA timezone on each concrete slot so later tenant setting changes do not reinterpret existing slots.
- Convert all slot start/end instants to UTC at the boundary and persist them under a documented UTC convention. Return local time plus timezone/offset to clients.
- Keep operational “service date” and pickup wall time for legacy compatibility, but interpret them using the slot's timezone; do not silently rewrite old rows as UTC.
- Require a slot public ID for a timed activity. For new client-supplied local wall times, require an explicit offset/fold policy; reject nonexistent DST times and require a client choice for ambiguous repeated times.
- Use one authoritative server/database UTC clock for quote/reservation expiry, not a browser timestamp; ensure DB session timezone and app timezone behavior are tested.
- Compare resource intervals as UTC half-open intervals and include approved turnaround buffers.

**CLIENT DECISION REQUIRED:** Confirm Extra Fun's operating timezone, whether all channels/products use it, pickup/time display rules, and how to handle DST ambiguity. No `Africa/Cairo` or other tenant timezone is assumed.

**Blocks implementation:** **Yes** for slot generation, availability, cutoff rules, and booking comparisons. Existing historical dates remain unchanged absent an approved data reconciliation.

### 6. Repository and environment consistency / pricing discrepancy

**Repository match:** The remote is ReefOS, consistent with the task's intended repository. The local folder name and the prior blueprint's `systemMarinaX` label are not grounds to switch repositories. Continue auditing this remote; do not switch branch or alter Git state.

**Final schema derivable from migrations — DERIVED, not live verified:**

- `prices_company` uses `id_company`, `id_trip`, `id_currency`, `price_adult`, `price_child`, and `price_infant`; the migration adds tenant ownership later. The creation migration defines restrictive FKs but no composite uniqueness for company/trip/currency.
- `operations` originally had `id_customer`, then migrations remove it and add `customer_id`. It has `trip_date`, nullable `trip_time`/`time_pickup`, participant counts, per-category prices, totals, payment status, and later `tenant_id` and `booking_status`.
- `currencies` is shared/global; `companies`, `trips`, `extras`, and booking data are tenant-owned by later migrations. `tenant_id` was added nullable and backfilled; this does not prove the live data has no nulls or tenant mismatches.
- `operation_extras` already stores extra ID, price, quantity, line total, and unique `(operation_id, extra_id)`. It should be reused.
- `boats.company_id` was later removed; boats are tenant-owned, not currently company-FK-bound.
- `operation_assignments` is date-only and uses an untyped resource ID; it cannot safely serve as the proposed time interval inventory ledger.

Relevant migrations: [prices_company](../../database/migrations/2026_09_08_111011_create_prices_company_table.php), [operations](../../database/migrations/2026_09_08_104322_create_operations_table.php), [tenant backfill](../../database/migrations/2026_10_26_000005_create_tenants_and_assign_existing_data.php), [booking lifecycle/idempotency](../../database/migrations/2026_11_18_000006_add_booking_idempotency_lifecycle_and_payments.php), and [boat company removal](../../database/migrations/2026_09_28_102229_remove_company_from_boats_table.php).

**`Prices_company` discrepancy — resolved at code-path level; live state still unverified:**

- The model `$fillable` lists `company_id`, `trip_id`, `currency_id`, `adult_price`, `child_price`, and `infant_price`, and its relationships rely on conventional FK names. Those do not match the migration's physical columns (`id_company`, `id_trip`, `id_currency`, `price_*`); the relationships/fillable-based create/update would be incorrect against the migrated schema.
- Workspace search found no production call site using `Prices_company` Eloquent directly. The admin write uses `TenantDatabase::table('prices_company')->updateOrInsert()` with physical column names; the API and staff reads use the same physical names. The API feature test creates/inserts those physical columns and asserts the resulting booking.
- Therefore the existing tested request path works through query builder and does **not** prove that the Eloquent model works, nor that the production database has the expected final schema.
- **P0.0 blocker:** obtain sanitized read-only schema/index metadata and duplicate/tenant-integrity counts from the target DB, without exporting data or credentials. Verify the migration ledger and final columns. Reconcile or quarantine duplicate company/trip/currency price rows before adding any rule fields or uniqueness. Until this is done, do not extend pricing or rely on the model.

**Additional blueprint compatibility findings:**

- The `trips`-canonical/no-parallel-`products` decision is compatible with current `operations.id_trip`, prices, and pickup mappings.
- `trip_variants` are appropriate only for independent bookable packages (e.g. half/full day); confirm the actual product/variant matrix first.
- The target capacity flow must also integrate `StaffOperations` and resource-assignment cancellation, not only `WebsiteController`.
- `booking_reservations` currently proposes `tenant_id` cascade deletion. For quote/reservation/booking audit history, tenant deletion should be archival/soft deactivation or restricted by retention policy, not silently cascading away commercial evidence.
- Quote snapshots must exclude unnecessary customer/medical PII. Customer data should be submitted at booking creation; quote pricing needs only the classification/quantity inputs.
- Channel/company/client invariants should be enforced by composite tenant/company FKs where practicable, not only controller validation. Shared currency remains a global FK.
- No live migrations/schema inspection was performed; database version and operational data remain separate blockers.

**Blocks implementation:** **Yes** for any pricing extension or migration. The code path and likely migration schema are understood, but target DB state and duplicates are not.

## C. Existing Architecture Assessment

| Capability | Verified reuse / gap |
|---|---|
| Product/catalog | Reuse `trips` as the sole product identity. Add public IDs/type only after catalog mapping. Do not add a parallel `products` table. |
| Variants | No current variant model. Add only where half/full day, course package, or duration has independently selectable price/availability. |
| Price | Reuse physical `prices_company` rows for legacy adult/child/infant prices. Resolve the model mismatch and existing duplicates before extension. |
| Participants | Existing `operations` only has adult/child/infant aggregate counts. No diver/non-diver or qualification dimensions. A per-person classification structure is needed if pricing/prerequisites depend on overlapping attributes. |
| Booking | Reuse `operations`, `customers`, `BookingLifecycleService`, status history, and booking ID. Current API/staff flows create operations directly, so capacity integration must cover both. |
| Extras | Reuse `extras` and `operation_extras` for catalog values and booking price/quantity snapshots. A product/variant applicability mapping is missing; finite rental stock needs reservation inventory. |
| Pickup | Reuse `hotels` and `trips_hotels`; pickup is a local TIME and there is no pickup capacity/tenant timezone strategy. |
| Resources | Reuse `boats` and `staff_profiles` as resource records only. Current `operation_assignments` is not a safe interval claim ledger. |
| Tenant isolation | Reuse `TenantContext`, `BelongsToTenant`, and `TenantDatabase`; add same-tenant/company constraints and scoped authorization for all new relations. |
| Idempotency | Reuse `tenant_api_idempotency_keys` only with a deliberate client/endpoint namespace. Current unique key is `(tenant_id,key_hash)` and booking key is optional. Do not let client/endpoint keys collide. |
| Payments | Existing provider-independent rows/services exist, but `AppServiceProvider` binds `UnconfiguredPaymentGateway`; payment provider configuration is empty in the sample. Keep payment writes disabled until a real adapter and tenant-safe configuration are approved. |
| WhatsApp | Only `WhatsAppTransport` interface was found; no implementation/binding. Do not describe WhatsApp notifications as operational. |
| OpenAPI | `public_html/openapi.yaml` documents existing routes; `WebsiteApiTest` checks registered API paths/methods exist in it. New routes/contracts require tests and documentation updates after implementation approval. |

### Extra Fun product coverage (conditional on client rules)

- **Daily diving:** Trips + independently bookable package variants and concrete date/time slots can represent durations, dive counts, additional days, and capacity. Independent participant attributes, existing hotel pickup mappings, and existing extras/operation-extras cover the basic booking shape. Exact schedule, included services, per-person pricing, and equipment availability remain client inputs.
- **Diving courses:** A Trips product and variant can represent course level/package; validated course configuration can hold prerequisites and age rules; slots and typed staff resources can support scheduled instructor capacity. Cohort/enrollment, certification workflow, medical review, and dive execution are not delivered by the current system and should remain deferred unless the first sales integration requires them.
- **Private boats:** A fixed booking-level price and half/full-day variants are representable in the target design; passenger capacity alone is not exclusivity. The separate boat allocation decision in Point 1 blocks launch configuration.
- **Snorkeling and marine activities:** Reuse Trips types/variants, concrete slots, participant capacity, and applicable extras for morning/afternoon/sunset sessions. No separate Extra Fun-specific product tables or hardcoded prices are justified.
- **Platform reuse:** These are proposed capabilities, not verified current features. Keep business-specific rules in tenant configuration and approved data, not Extra Fun-specific code branches.

## D. Database Change Plan (Proposal Only)

No tables/columns were created. This is an additive sequence proposal; the target DB version and live data must be confirmed first. For all new tenant-owned entities, require non-null `tenant_id`, tenant-aware FKs/indexes, and same-tenant parent validation. Prefer composite tenant FKs and same-company FKs where supported. Use `RESTRICT`/archival for booking/audit records; use cascade only for disposable configuration whose deletion cannot erase commercial history. All nullable fields below are intentional and must be justified.

### D.1 Existing table extensions

| Existing table / proposed fields | Why / relationships | Tenant, FK, deletion | Indexes / uniqueness / compatibility |
|---|---|---|---|
| `trips`: `public_id`, `product_type`, optional `product_category_id`, optional constrained configuration | Preserve canonical product ID and support public catalog/types/options. `product_category_id` is optional and can be deferred if initial catalog needs no grouping. | Existing tenant ownership; new category FK `RESTRICT`. Keep existing trip IDs/FKs. Validate JSON by product type; don't put authoritative capacity/price in JSON. | Unique `(tenant_id, public_id)` after backfill; `(tenant_id,status,product_type)`. No changes to old endpoint fields. |
| `prices_company`: **no P0 expansion until DB/row audit**; conditional nullable variant/channel/rule fields only after rule contract | Existing base pricing is physical columns `id_company/id_trip/id_currency/price_*`. | Preserve existing FKs and tenant ownership; enforce same-tenant company/trip, shared global currency. | First audit duplicate `(tenant_id,id_company,id_trip,id_currency)` rows. Add a deterministic uniqueness strategy only after duplicate remediation and approved rule semantics. Keep legacy amounts unchanged. |
| `operations`: nullable `booking_reservation_id`; optional immutable price snapshot if line snapshots cannot be reconstructed from current operation/extras | Link one converted hold to the existing booking. Keep `id_trip` canonical and current booking lifecycle. | FK to reservation `RESTRICT`; one reservation per operation. Existing rows remain null. | Unique nullable reservation FK; no existing booking totals rewritten. If detailed line snapshots are needed, preserve quote/operation-extra snapshot rows rather than trusting current mutable prices. |
| `operation_extras`: no duplicate table; only conditional approved tax/snapshot column | Existing extra ID, price, quantity, line total already capture selection. | Existing extra `RESTRICT`, operation cascade; backfill current nullable tenant ID from operation before any NOT NULL constraint. | Existing unique `(operation_id,extra_id)`; retain legacy records. |
| `tenant_api_idempotency_keys`: optional nullable client reference / namespace only if implementation requires it | Reuse response/hash store. New key hash must include tenant + client + endpoint namespace. | Client FK restricted or retained as nullable only for legacy rows; tenant ownership. | Existing `(tenant_id,key_hash)` retained unless a rehearsed replacement is required; preserve old-key hash behavior during migration. |

### D.2 Proposed table-by-table review

| Table | P0 classification | Purpose / relationships / tenant ownership | Required constraints / indexes / nullability / deletion behavior |
|---|---|---|---|
| `product_categories` | **Defer unless catalog grouping is required.** | Optional labels for `trips`; tenant-owned. No existing equivalent category table is established for this catalog use. | `tenant_id`, `name`, `slug`, `status`; unique `(tenant_id,slug)`; tenant FK; trip category FK restrict. Avoid adding solely for future-proofing. |
| `trip_variants` | **Conditional P0** for genuinely distinct selectable duration/package/schedule variants. | Child of `trips`; tenant-owned. | Required `trip_id`, `public_id`, `code`, `status`; unique `(tenant_id,public_id)` and `(tenant_id,trip_id,code)`; composite tenant/trip FK; `configuration` nullable and validated. Restrict deletion once priced/slot/booking referenced. |
| `participant_classifications` | **P0 if classification-based price/prerequisite rules are approved.** | Tenant-controlled independent dimensions such as age band, activity role, certification. | Unique `(tenant_id,code)`; index `(tenant_id,dimension,status)`; nullable age bounds/exclusive group; tenant FK; referenced codes should deactivate rather than delete. Prevent overlap/equal-priority ambiguity in service and test on target engine. |
| `booking_participants` | **Conditional P0** when price/prerequisites are per-person; do not collect PII by default. | Child of `operations`; one per participant to attach multiple independent classifications. | Tenant and operation FKs; operation cascade only under approved booking retention; `(tenant_id,operation_id)` and unique nullable public ID. Name/DOB/nationality nullable and collected only if approved; exclude medical data. |
| `booking_participant_classifications` | **P0 if `booking_participants` is used.** | Many-to-many participant/classification join; tenant-owned. | Composite same-tenant participant/classification FKs; unique `(tenant_id,booking_participant_id,participant_classification_id)`; cascade join rows, restrict/deactivate classifications. |
| `sales_channels` | **Required P0.** | Website/source binding to one tenant, one company, and one shared/global currency. | Non-null tenant/company/currency; composite `(tenant_id,company_id)` FK; currency FK to global `currencies`; unique `(tenant_id,code)` and supporting `(tenant_id,id,company_id)`; index tenant/company/status. CORS origins nullable and not an auth boundary. |
| `api_clients` | **Required P0.** | Rotatable credential, one tenant and exactly one company. | Store key prefix + hash only; reveal secret once; unique prefix; tenant/company composite FK; unique/supporting `(tenant_id,id,company_id)`; indexes by tenant/company/status/expiry. Revocation/expiry nullable; archive rather than cascade referenced audit data. |
| `api_client_sales_channels` | **Required if client may use multiple channels; otherwise optional for one initial channel.** | Explicit same-tenant, same-company client/channel binding. | Unique client/channel; include tenant/company or equivalent composite constraints so the DB rejects cross-company links; cascade only pivot rows. |
| `availability_slots` | **Required P0.** | Concrete trip/variant occurrence; authoritative participant capacity. | Non-null tenant/trip/start/end/capacity/status/public ID; optional variant; unique `(tenant_id,public_id)`; indexes tenant/product/start/status and tenant/start; positive capacity and end-after-start checks plus service validation. Composite trip/variant same-tenant FKs; referenced slots restrict deletion. Store timezone/UTC convention explicitly. |
| `slot_resources` | **Needs redesign before P0.5** for interval overlap and private-boat exclusivity. | Typed boat/staff/equipment assignment to a concrete slot; no polymorphic `resource_type/id`. | Typed tenant/resource FKs; exactly-one-type constraint; unique slot/resource; index resource/start/end for overlap. Add explicit shared/exclusive allocation policy. A static assignment row alone does not prevent overlapping different slots. Resource reassignment must lock the resource and reject conflicting intervals. |
| `booking_quotes` | **Required P0.** | Non-blocking, server-calculated immutable price/selection snapshot owned by client/channel/company/trip/variant. | Unique `(tenant_id,public_id)`; index tenant/client/status/expiry; tenant/client/channel/company composite integrity; global currency FK; quote expiration non-null. No inventory FKs/claims. Snapshot classification counts/IDs, not unnecessary customer/medical PII. Retain according to policy; do not cascade commercial history on tenant removal. |
| `booking_reservations` | **Required P0.** | Temporary capacity/resource hold, then converted/released state. | Unique `(tenant_id,public_id)`; unique nullable quote FK (one successful hold per quote); index `(tenant_id,status,expires_at)`; `expires_at` required for `held`, null allowed only for migrated converted legacy rows; `api_client_id`/quote nullable only for explicit legacy migration source. Restrict referenced client/quote deletion; retain historical ledger. |
| `booking_reservation_items` | **Required P0.** | Reservation-to-slot quantity ledger. | Unique reservation/slot; index tenant/slot/reservation; positive quantity; composite same-tenant FKs; reservation delete cascade only if retention policy allows, slot restrict. This is the sole participant capacity sum. |
| `reservation_resource_items` | **Conditional P0** for finite equipment/extras; **needs extension/redesign** if it must cover exclusive boats. | Existing proposal only holds selected equipment quantity against configured slot allocation. | Unique reservation-item/resource; positive quantity and resource/tenant FKs; index resource and active reservation state. For boats, add interval claims/exclusive semantics or a separate typed `resource_reservations` ledger; do not assume equipment quantity logic provides boat exclusivity. |
| `trip_extras` | **P0 if product-specific extras are sold.** | Applicability mapping from trip (optionally variant) to existing `extras`; no duplicate extra catalog. | Unique `(tenant_id,trip_id,extra_id)` or variant-aware equivalent; same-tenant trip/extra FKs; `is_required` and eligibility configuration nullable/validated. Restrict deletion when referenced; use `operation_extras` for sold snapshot. |

### D.3 Additive migration order

1. **Preflight only:** verify target DB engine/version, migration ledger, final columns/FKs/indexes, tenant nulls/mismatches, duplicate prices, operation/assignment overlap, and retention. No schema writes during this audit.
2. Add API-client/channel structures and compatibility authorization path. Backfill only explicitly mapped clients; never widen keys.
3. Add trip public ID/type and any approved category/variant tables; backfill in chunks, validate uniqueness, then enforce required keys.
4. Reconcile price model/schema and duplicate base prices before any price extension. Add participant configuration only where client-approved rules need it.
5. Add concrete slot and typed/interval resource allocation schema after timezone and shared/exclusive policy are fixed.
6. Add quotes, reservations, items, equipment/resource claim structures; only then add nullable unique reservation FK and snapshot field to `operations`.
7. Reconcile future legacy operations/resource assignments into capacity before publishing any affected slot. Ambiguous bookings block slot activation.
8. Add product-extra applicability if needed; retain all old `extras`/`operation_extras` rows and API behavior.
9. Enforce NOT NULL/composite FKs/unique constraints only after duplicate/null audits and target-engine migration rehearsal.

No destructive or rename migration is justified for P0. Use staged nullable columns, chunked backfills, dual-compatible application rollout, and explicit rollback by disabling new routes/slots—not deleting booking history.

## E. API Security Plan

1. Authenticate new API clients by prefix + strong secret hash; compare hashes safely; never store/reveal plaintext after issuance. Define scopes per resource and write action.
2. Resolve a request context containing tenant, one company, one authorized sales channel, and the channel's server-selected currency. If a client has multiple channels, require a channel code and verify the pivot; if exactly one, it may be inferred.
3. Derive tenant/company/currency from that context. Reject mismatching client-supplied company or currency rather than using them as selectors.
4. Scope catalog, prices, quote, availability, booking read, status, payment, and refund queries to the bound company/channel as well as tenant. Opaque IDs are not authorization.
5. Use per-client rate limits plus optional IP limits; treat CORS only as browser policy.
6. Namespace idempotency by tenant/client/endpoint and canonical request hash; preserve existing tenant-wide legacy hash behavior only for the old endpoint during migration.
7. Migrate legacy credentials with explicit company/channel mapping. Keep unbound behavior only for proven single-company integrations in a time-bounded migration; deny ambiguous clients rather than granting broad read access.
8. Exercise existing and new endpoints with two tenants, two companies within one tenant, multiple channels, revoked/expired clients, global currencies, known booking references, and raw-Origin spoofing.

## F. Booking and Capacity Flow

`Catalog → Availability → Quote → Reservation → Booking → Confirmation`

1. **Catalog:** expose active canonical Trips and only approved variants for the authenticated channel. Never duplicate product identity.
2. **Availability:** list concrete slots in their timezone; calculate capacity and resource conflicts without creating a hold. No published slot means not bookable.
3. **Quote:** calculate server-side price from client/channel/company/currency, product, variant, date/slot, and independent participant attributes. Persist an immutable snapshot/expiry. No row locks or inventory changes.
4. **Reservation:** accept quote ID only. Under one transaction, lock quote-selected slots and required resources in stable order; check quote validity, capacity, exclusivity, staff/equipment limits, and overlaps; write hold/items/resource claims and idempotency result atomically. Failed capacity creates no hold.
5. **Expiry:** hold TTL begins at successful creation. Expiry is absolute UTC, evaluated immediately using the single trusted clock, not a delayed cleanup job. Same-key replay returns same reservation and original expiry; never renew. Expired hold cannot convert.
6. **Booking:** under lock, verify held/unexpired/owned/unused reservation; create existing `customers`/`operations`/participant and `operation_extras` snapshots; convert reservation in the same transaction. Unique operation-to-reservation link prevents replay-created duplicates. Quote expiry after hold creation does not change the held price.
7. **Confirmation:** use existing lifecycle transitions. Only after an authorized cancellation should a converted reservation/resource claim become released, in the same transaction. Decide explicitly whether full refund releases capacity; current `paid → refunded` is not a cancellation transition.
8. **Legacy:** before slot activation, match future operations by trip/date/time and resource assignment to slots, create converted migration ledger rows once, and block ambiguous cases. Existing non-capacity-managed trips retain legacy routes. For capacity-managed trips, API *and staff* writes must use the same reservation service or be rejected.

## G. Client and Owner Decisions Required

### Business decisions — Extra Fun

1. Confirm exact active products, durations, half/full-day/package variants, dive counts, course levels, age/prerequisite requirements, and public visibility.
2. Confirm prices, currencies, taxes/fees, per-booking versus per-person rules, adult/child/diver/non-diver combinations, supplements/overrides, rounding, deposit/payment, discount validity, and price dates.
3. **Private boat:** shared versus exclusive for each product, the asserted capacity of eight, charter price semantics, half/full-day durations, cleanup/turnaround buffers, and cancellation effect on boat availability.
4. Confirm schedules, timezone, recurrence, blackout dates, cutoffs, lead/advance windows, and whether a package consumes one or several slots.
5. Confirm capacity sources and numeric limits for participants, boats, instructors/divemasters, rental items and sizes; how staff/resource reassignments work.
6. Confirm pickup hotels, guest/free-text pickup, pickup pricing/time, and any pickup capacity.
7. Confirm per-person fields and classifications needed at quote/booking; names/DOB/nationality only where necessary. Medical/safety fields, consent, access and retention remain out of P0.
8. Approve one cancellation/refund/reschedule policy; the blueprint records conflicting public terms, which are observations only.
9. Confirm payment provider, currencies/methods, verification, refund behavior, and whether payments are enabled at launch.
10. Confirm Extra Fun tenant/company, channel/domain, catalog/content ownership, WooCommerce integration/handoff, and who owns credential rotation and website-side integration.
11. Confirm booking, cancellation, confirmation, language, consent, tax/invoice, and support communication rules. Email exists; WhatsApp has no configured transport.

### Technical/owner decisions

1. Supply sanitized read-only target DB engine/version, engine type, isolation/session timezone, migration status, and schema/index/duplicate/tenant-integrity results. Do not send DB credentials or production records.
2. Confirm Hostinger's supported engine/version and a disposable staging DB that matches production.
3. Confirm public API version/path for reservation-based booking versus legacy `POST /api/v1/bookings`; define a discriminator or a separate additive endpoint.
4. Inventory each tenant key/integration/company and choose re-use of a verified one-company hash versus re-issuing keys. No multi-company hash cloning.
5. Approve tenant/slot timezone strategy and exact quote/hold TTLs (blueprint's 15/10 minute values are unapproved recommendations).
6. Approve hard-delete/retention policy for quotes, reservations, bookings, client credentials and tenant offboarding.
7. Confirm all legacy staff/admin booking and resource-assignment paths that must be migrated or disabled for capacity-managed Trips.

## H. Risk Register

| Severity | Risk / evidence | Mitigation |
|---|---|---|
| **High** | Tenant API key has no company/channel binding; current read routes expose all tenant companies and all companies' prices, while booking writes accept any tenant company and active global currency. | P0.1 client/company/channel resolution; mapped legacy behavior only; tests for cross-company reads/writes. |
| **High** | Current website and staff booking paths do not reserve capacity; internal staff path creates operations directly. | Central inventory service and explicit migration of every writer; block/route staff path for managed slots; final-seat concurrency tests on target DB. |
| **High** | Private boat exclusivity cannot be inferred from capacity and is not specified in the proposed schema. | Client approval; explicit shared/exclusive mode and interval claims; serialized resource row lock and overlap tests. |
| **High** | Production DB engine/version and Hostinger compatibility are unknown; SQLite tests cannot verify row locks or MySQL-specific migrations. | Obtain sanitized metadata; matching staging; engine-specific migration and concurrent load tests before rollout. |
| **High** | `Prices_company` model/relations use mismatched names; model is unused in current reads/writes, but actual DB state and duplicate base prices are unknown. | Target schema/index audit; repair or retire model in implementation; dedupe and constrain base rows before any extension. |
| **High** | Expiration, cancellation/refund, and converted reservation status can diverge unless atomically coupled with operation lifecycle. | One reservation ledger; atomic transition/release; define refund-vs-cancel policy; idempotent terminal states. |
| **High** | Naive local dates/times and UTC `today()` lack tenant timezone/DST semantics. | Tenant and slot IANA timezone, UTC instants, explicit DST policy; preserve old rows and test boundary cases. |
| **Medium** | Blueprint places new reservation booking on existing `/api/v1/bookings` without a sufficiently explicit branch before legacy validation. | Define request discriminator or separate endpoint and OpenAPI schema before coding. |
| **Medium** | Legacy `operation_assignments` is date-only and polymorphic; legacy future bookings may already consume boats/staff. | Reconcile future assignment schedule; block slot publishing for ambiguous or conflicting assignment. |
| **Medium** | New quote/reservation table tenant-cascade could erase audit history on tenant deletion. | Archive/deactivate tenants; restrict hard delete per retention/legal policy. |
| **Medium** | No unique base price composite was found; `updateOrInsert` without DB uniqueness can race/allow duplicates. | Duplicate audit; supported uniqueness for base rules; deterministic rule identifiers for additive rules. |
| **Low** | Category table and rich normalized course/schedule features may be overbuilt before catalog choices. | Defer categories and advanced course/dive execution until actual initial catalog requires them. |

## I. Recommended Implementation Sequence and Acceptance

No phase after P0.0 is authorized by this audit.

| Phase | Scope / acceptance criteria | Required tests before approval |
|---|---|---|
| **P0.0 — Pre-implementation audit** | Complete this audit; confirm repo/DB/Hostinger; reconcile live schema and pricing duplicates; approve business decisions and endpoint contract. No source/schema writes. | Review evidence; read-only metadata checklist; no production test writes. Audit is complete but blockers remain, so **P0.0 exit criteria are not met**. |
| **P0.1 — API security** | New API clients bind one tenant/company and explicit same-company channels; currency is server-resolved; legacy keys mapped/reissued or denied; no cross-company read/write; no secret in logs. | Existing legacy regression; invalid/expired/revoked key; scope tests; two tenants/two companies; two channels; booking/resource ownership; rate limiting by client; forged Origin; key rotation overlap. |
| **P0.2 — Catalog** | Trips remains the single canonical product; public identifiers and only client-approved variants; existing trip API payload remains compatible. | Existing endpoint response regression; tenant/channel catalog visibility; public ID uniqueness; variant same-trip ownership; inactive/unpublished products hidden. |
| **P0.3 — Pricing** | Reconcile physical schema/model and duplicates first; deterministic server pricing with non-exclusive participant attributes and booking-level fixed price. | Legacy price path; adult+diver overlapping attributes; child/non-diver; fixed boat amount not multiplied; precedence/rounding; invalid/missing/duplicate rules; client amount tampering; currency binding. |
| **P0.4 — Quotes** | Persist immutable quote and expiry; quote has zero inventory effect; PII minimized. | Verify no reservations/resource claims/capacity decrement; same-key replay; changed payload conflict; expired replay; quote price remains unchanged after price edit. |
| **P0.5 — Availability** | Publish explicit slots with timezone and capacity; enforce shared/exclusive boat/staff/equipment conflicts; no `remaining_capacity` authority. | Target-engine concurrent slot assignment; two overlapping slots on one boat; exclusive vs shared collision; half/full-day buffers; DST ambiguous/nonexistent times; staff/equipment limits; no-slot unavailable. |
| **P0.6 — Reservations and booking** | One ledger enforces capacity; hold/expiry/conversion/cancellation atomic; existing `operations` remains booking record; all API and staff flows integrated. | Simultaneous final seat/boat; holds consume only while active; expiration/replay; changed idempotency body; duplicate conversion; rollback across multi-slot failure; cancellation lifecycle releases once; refund policy; old request cannot bypass; legacy backfill consumes once; ambiguous legacy blocks activation. |
| **P0.7 — Controlled rollout** | Rehearsed additive migrations; approved catalog/price/capacity; key/channel cutover and rollback; all managed trips have no bypass. | Full acceptance/regression/security suite on disposable production-equivalent DB; migration rehearsal from current schema/data shape; end-to-end Extra Fun integration; rollback rehearsal without deleting history. |

## J. Final Audit Outcome

- **Repository confirmed:** `Keroles12345/ReefOS`, branch `main`; local folder name differs but remote matches.
- **Final architecture direction:** retain Trips as canonical products; separate quote from reservation; server-side price snapshots; participant attribute dimensions; capacity derived from one reservation ledger; company/channel-bound API clients; timezone-aware concrete slots; defer medical/safety and advanced diving operations.
- **Blocking decisions/evidence:** production DB/Hostinger engine and version; live pricing schema and duplicate audit; private-boat exclusivity; tenant/slot timezone; client price/schedule/capacity/cancellation/payment rules; all staff/API booking path migration; explicit legacy API mappings and new booking endpoint contract.
- **Implementation readiness:** **NOT READY — do not start P0.1.**
- **Validation:** read-only source/config/test inspection only. `php artisan --version` and local `PDO::getAvailableDrivers()` were run; application tests, migrations, live DB metadata queries, deployment checks, and production operations were not run.
- **Changes:** this new audit report only. The existing blueprint and pre-existing untracked files were not edited.

## K. Outstanding Evidence and Safe Collection Procedure

**As of 2026-11-27 22:17 +02:00:** No database metadata, Hostinger confirmation, sanitized integrity counts, or new Extra Fun/owner decisions were supplied with the continuation request. The repository, branch, commit, and worktree were rechecked: remote `Keroles12345/ReefOS`, branch `main`, commit `02aeefa67340af267eb5f3eb434a150f7febf600`; no tracked changes, with untracked `docs/extra-fun/` and `docs/implementation/`. No database connection or query was made. Existing code/migration-derived findings remain unchanged.

The following is a request package for the authorized database administrator/Hostinger account owner. It is **not** authorization for this assistant to access a database. Run only read-only statements inside an already authenticated Hostinger/phpMyAdmin or DBA session. Do not paste credentials, connection strings, secret values, customer rows, booking IDs, API-key material, payment references, or production exports into chat. Return sanitized versions, counts, and schema metadata only. If a table/column does not exist, report that rather than adapting the application schema by guesswork.

### K.1 Hostinger target engine and runtime evidence

**Required output:** actual database product and server version; default and per-table storage engine; session/global timezone; SQL mode; transaction-isolation level; matching Hostinger staging DB product/version; whether staging can be reset/recreated without production data. The version reported by the running database is authoritative; a Hostinger marketing/support page alone is not.

For a confirmed MySQL/MariaDB connection, run these read-only queries in the authenticated database console:

```sql
SELECT
    VERSION() AS server_version,
    @@version_comment AS server_product,
    @@default_storage_engine AS default_storage_engine,
    @@session.time_zone AS session_time_zone,
    @@global.time_zone AS global_time_zone,
    @@session.sql_mode AS session_sql_mode;
```

After the version query identifies the server, run the matching isolation variable:

```sql
-- MySQL versions exposing transaction_isolation:
SELECT @@transaction_isolation AS session_transaction_isolation;
```

```sql
-- MariaDB / older MySQL versions exposing tx_isolation:
SELECT @@tx_isolation AS session_transaction_isolation;
```

Confirm actual engines for the tables used by the booking/tenant paths:

```sql
SELECT table_name, engine
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
      'tenants', 'companies', 'currencies', 'trips', 'prices_company',
      'customers', 'operations', 'hotels', 'trips_hotels', 'extras',
      'operation_extras', 'boats', 'staff_profiles', 'operation_assignments',
      'tenant_api_idempotency_keys', 'operation_status_history'
  )
ORDER BY table_name;
```

If the server reports SQLite, PostgreSQL, or another product, **do not run the MySQL/MariaDB statements as-is**. First provide the product/version only; then use vendor-specific read-only metadata queries. For SQLite, the basic version/locking metadata checks are:

```sql
SELECT sqlite_version() AS server_version;
PRAGMA foreign_keys;
PRAGMA journal_mode;
PRAGMA busy_timeout;
```

For PostgreSQL:

```sql
SELECT version() AS server_version,
       current_setting('transaction_isolation') AS transaction_isolation,
       current_setting('TimeZone') AS session_timezone;
```

**Why required:** SQLite tests do not establish production `FOR UPDATE`, concurrent-write, FK/check, unique-NULL, or migration behavior. Hostinger's engine/version and table engine decide which constraints and locking strategy can safely enforce capacity and private-boat conflicts.

### K.2 Live schema, indexes, constraints, and migration state

For a confirmed MySQL/MariaDB target, return schema metadata only for the table list above:

```sql
SELECT table_name, column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN (
      'tenants', 'companies', 'currencies', 'trips', 'prices_company',
      'customers', 'operations', 'hotels', 'trips_hotels', 'extras',
      'operation_extras', 'boats', 'staff_profiles', 'operation_assignments',
      'tenant_api_idempotency_keys', 'operation_status_history'
  )
ORDER BY table_name, ordinal_position;
```

```sql
SELECT table_name, index_name, non_unique, seq_in_index, column_name
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN (
      'tenants', 'companies', 'currencies', 'trips', 'prices_company',
      'customers', 'operations', 'hotels', 'trips_hotels', 'extras',
      'operation_extras', 'boats', 'staff_profiles', 'operation_assignments',
      'tenant_api_idempotency_keys', 'operation_status_history'
  )
ORDER BY table_name, index_name, seq_in_index;
```

```sql
SELECT k.table_name, k.constraint_name, k.column_name,
       k.referenced_table_name, k.referenced_column_name, k.ordinal_position,
       r.update_rule, r.delete_rule
FROM information_schema.key_column_usage AS k
LEFT JOIN information_schema.referential_constraints AS r
  ON r.constraint_schema = k.constraint_schema
 AND r.constraint_name = k.constraint_name
 AND r.table_name = k.table_name
WHERE k.constraint_schema = DATABASE()
  AND k.table_name IN (
      'companies', 'trips', 'prices_company', 'customers', 'operations',
      'trips_hotels', 'operation_extras', 'boats', 'staff_profiles',
      'operation_assignments', 'tenant_api_idempotency_keys',
      'operation_status_history'
  )
  AND k.referenced_table_name IS NOT NULL
ORDER BY k.table_name, k.constraint_name, k.ordinal_position;
```

If supported by the identified version, report check constraints as metadata:

```sql
SELECT tc.table_name, tc.constraint_name, tc.constraint_type, cc.check_clause
FROM information_schema.table_constraints AS tc
LEFT JOIN information_schema.check_constraints AS cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.constraint_schema = DATABASE()
  AND tc.table_name IN (
      'prices_company', 'operations', 'operation_extras', 'boats',
      'operation_assignments'
  )
ORDER BY tc.table_name, tc.constraint_name;
```

Confirm migration application state without reading application rows:

```sql
SELECT COUNT(*) AS applied_migration_count FROM migrations;

SELECT migration, batch
FROM migrations
WHERE migration LIKE '%prices_company%'
   OR migration LIKE '%tenant%'
   OR migration LIKE '%booking_idempotency%'
   OR migration LIKE '%boats%'
   OR migration LIKE '%operation_assignments%'
ORDER BY batch, migration;
```

If the migration table or metadata views are not accessible, report that fact and have the DBA provide sanitized DDL/schema metadata through an approved channel. Do not request elevated credentials.

**Why required:** checked-in migrations describe intended schema, not necessarily the Hostinger deployment. The model discrepancy, nullable tenant columns, composite FK/index feasibility, duplicate prices, and migration compatibility can only be resolved against actual metadata and data-quality counts.

### K.3 Sanitized data-integrity and pricing counts

Run only aggregate counts; do not return row-level customer or booking data. First confirm the tables/columns exist using K.2.

Tenant-null counts for the tenant-owned tables involved in catalog, pricing, booking, and resource assignments:

```sql
SELECT 'companies' AS table_name, COUNT(*) AS rows_total,
       COALESCE(SUM(tenant_id IS NULL), 0) AS null_tenant_rows FROM companies
UNION ALL
SELECT 'trips', COUNT(*), COALESCE(SUM(tenant_id IS NULL), 0) FROM trips
UNION ALL
SELECT 'prices_company', COUNT(*), COALESCE(SUM(tenant_id IS NULL), 0) FROM prices_company
UNION ALL
SELECT 'customers', COUNT(*), COALESCE(SUM(tenant_id IS NULL), 0) FROM customers
UNION ALL
SELECT 'operations', COUNT(*), COALESCE(SUM(tenant_id IS NULL), 0) FROM operations
UNION ALL
SELECT 'hotels', COUNT(*), COALESCE(SUM(tenant_id IS NULL), 0) FROM hotels
UNION ALL
SELECT 'trips_hotels', COUNT(*), COALESCE(SUM(tenant_id IS NULL), 0) FROM trips_hotels
UNION ALL
SELECT 'extras', COUNT(*), COALESCE(SUM(tenant_id IS NULL), 0) FROM extras
UNION ALL
SELECT 'operation_extras', COUNT(*), COALESCE(SUM(tenant_id IS NULL), 0) FROM operation_extras
UNION ALL
SELECT 'boats', COUNT(*), COALESCE(SUM(tenant_id IS NULL), 0) FROM boats
UNION ALL
SELECT 'staff_profiles', COUNT(*), COALESCE(SUM(tenant_id IS NULL), 0) FROM staff_profiles
UNION ALL
SELECT 'operation_assignments', COUNT(*), COALESCE(SUM(tenant_id IS NULL), 0) FROM operation_assignments;
```

`currencies` is global/shared and is intentionally excluded from the tenant-null list.

Count duplicate legacy price keys without returning company/trip IDs or prices:

```sql
SELECT COUNT(*) AS duplicate_key_groups,
       COALESCE(SUM(rows_per_key - 1), 0) AS duplicate_extra_rows
FROM (
    SELECT COUNT(*) AS rows_per_key
    FROM prices_company
    GROUP BY tenant_id, id_company, id_trip, id_currency
    HAVING COUNT(*) > 1
) AS duplicate_groups;
```

Count missing/null/cross-tenant price references (currency is global, not tenant-scoped):

```sql
SELECT COUNT(*) AS price_rows,
       COALESCE(SUM(p.tenant_id IS NULL OR c.id IS NULL OR t.id IS NULL), 0)
           AS null_tenant_or_missing_parent_rows,
       COALESCE(SUM(
           c.id IS NOT NULL AND t.id IS NOT NULL
           AND (
               (p.tenant_id IS NOT NULL AND p.tenant_id <> c.tenant_id)
               OR (p.tenant_id IS NOT NULL AND p.tenant_id <> t.tenant_id)
               OR c.tenant_id <> t.tenant_id
           )
       ), 0) AS cross_tenant_rows
FROM prices_company AS p
LEFT JOIN companies AS c ON c.id = p.id_company
LEFT JOIN trips AS t ON t.id = p.id_trip;
```

Have the DBA run equivalent aggregate tenant-parent checks for `operations` against its trip/company and any non-null customer/hotel/creator references; `trips_hotels` against trip/hotel; `operation_extras` against operation/extra; and `operation_assignments` against operation plus its typed boat/vehicle resource. Return only counts for missing parents, null tenant ownership, and tenant mismatches. Do not return parent IDs or names.

**Why required:** zero duplicates/nulls cannot be assumed from migrations. Any mismatch or duplicate needs controlled remediation before unique or same-tenant FK constraints are designed; current `updateOrInsert` does not replace a database uniqueness guarantee.

### K.4 Legacy booking/resource reconciliation counts

The client/owner must first provide an approved local service-date cutoff and timezone. Then ask the DBA to run an aggregate-only query in that time window:

```sql
-- Replace YYYY-MM-DD with the owner-approved local service-date cutoff.
SELECT trip_date, trip_time, booking_status, COUNT(*) AS operation_count,
       COALESCE(SUM(adults + children + infants), 0) AS participant_count,
       COALESCE(SUM(trip_time IS NULL), 0) AS missing_trip_time_count
FROM operations
WHERE trip_date >= 'YYYY-MM-DD'
GROUP BY trip_date, trip_time, booking_status
ORDER BY trip_date, trip_time, booking_status;
```

Summarize resource assignments without returning resource IDs:

```sql
SELECT assign_date, type, COUNT(*) AS assignment_count
FROM operation_assignments
GROUP BY assign_date, type
ORDER BY assign_date, type;
```

Separately, the authorized operator must resolve in the restricted environment which future bookings and assignments map unambiguously to each proposed slot/boat interval. Report only total matched, unmatched, ambiguous, and overlap-conflict counts; do not export customer names, contacts, booking IDs, or raw resource IDs. Because the existing assignments contain dates but not time intervals, aggregate SQL alone cannot prove that boat or staff overlap is safe.

**Why required:** A slot must not be published while uncounted future legacy bookings or ambiguous day-level resource assignments could consume its capacity.

### K.5 Outstanding decisions and why they block

No client/owner decision was supplied with this continuation request. Each item remains **CLIENT DECISION REQUIRED** or **OWNER DECISION REQUIRED** until recorded in an approved decision log.

| Decision owner | Exact question to resolve | Why it is needed / blocks |
|---|---|---|
| Extra Fun | Which daily dives, packages, dive counts, courses/levels, excursions, snorkeling/marine activities and private-boat products are active for launch? Which options are independent variants? | Determines catalog/variant records and what must be scheduled; prevents implementing speculative products. |
| Extra Fun | For every product, what are approved prices, currency, tax/fees, per-booking/per-person/day/hour rules, participant conditions, additive supplements/overrides, rounding, deposit, discount and price-validity dates? | Server pricing and quote acceptance cannot be built or tested without deterministic approved rules. Public prices are observations, not approval. |
| Extra Fun | Are private boats shared or exclusive per product? Does half/full day reserve the whole boat? Confirm passenger cap, charter-price semantics, interval buffers and whether a refund/cancel makes the boat available again. | Capacity does not provide exclusivity; determines boat-claim model and concurrency tests. |
| Extra Fun | Exact schedule/timezone, durations, recurrence, blackout dates, departure times, lead/cutoff/advance windows, and whether packages consume one or multiple sessions? | Required for slot generation, date validation, overlap and DST behavior. |
| Extra Fun | Capacity per slot and authoritative resource limits: boat, crew/instructor ratios, equipment counts/sizes, reassignments and tenant-wide versus company-specific resource sharing? | Determines the single capacity limit and resource assignment/conflict rules. |
| Extra Fun | Which hotel pickups apply, whether free-text pickup is allowed, pickup time/pricing, and whether pickup has a separate capacity? | Determines reuse/configuration of `trips_hotels` and whether pickup consumes an inventory resource. |
| Extra Fun | What participant attributes, minimum age/prerequisites/certification, and per-person data are required at quote versus booking? Which fields are optional? | Determines classification rules while minimizing personal-data collection; medical/safety data stays out of P0 absent separate approval. |
| Extra Fun | Approve one cancellation/refund/reschedule policy and define full-refund/no-show/force-majeure/product-specific rules. | The public Terms and Refund Policy conflict; cancellation determines capacity release and payment behavior. |
| Extra Fun | Which payment provider/methods/currencies and deposit/full-payment rules are required, and is payment enabled at first launch? | Current gateway is unconfigured; payment must remain disabled until a real provider and callbacks are verified. |
| Extra Fun + owner | Which tenant/company/channel/domain owns the integration; is WordPress/WooCommerce content authoritative; how does server-to-server checkout hand off quote/reservation/booking; who rotates credentials? | Required for client/channel authorization, endpoint contract, product ownership and cutover. |
| Extra Fun | Confirm customer communications, language, terms/consent, invoice/tax requirements and support workflow; confirm whether email is enough. | Determines required data/notification behavior; WhatsApp has no provider implementation. |
| Owner / DBA | What is the actual production and staging DB product/version, storage engine, isolation, timezone, and Hostinger supported version? | Required to approve FKs/checks/locking and ensure staging matches production. |
| Owner / DBA | Supply sanitized K.2/K.3 outputs and resolve price duplicates, null tenant ownership and cross-tenant references. | Required before pricing schema changes or tenant-safe constraints. |
| Owner | Map each legacy key/integration to exactly one company and authorized channel(s); which clients may keep a mapped legacy path, and when do unmapped keys expire? | Existing keys are tenant-wide; broad reads are not safe merely because a key lacks write scopes. No hash cloning across companies. |
| Owner / API owner | Choose a versioned reservation-to-booking endpoint or an explicit discriminator on the existing route; define client channel selection and idempotency namespace. | Avoids breaking legacy validation and prevents clients from choosing company/currency authority. |
| Owner / Extra Fun | Approve quote and hold TTLs (the blueprint's 15-minute quote/10-minute hold are only recommendations), expiry clock, and whether holds survive quote expiry. | Needed for checkout UX and exact idempotency/expiry behavior. |
| Owner | Approve hard-delete/archive retention for tenants, clients, quotes, reservations, bookings and payment/audit history. | Prevents tenant offboarding from cascading away commercial evidence. |
| Owner / operations | Identify every API, staff, admin and resource-reassignment flow that creates/cancels a booking and choose the cutover behavior for capacity-managed Trips. | Every writer must share one capacity ledger; otherwise internal overbooking remains possible. |
| Owner / operations | Approve how legacy future operations and date-only resource assignments are mapped, who resolves ambiguous rows, and what blocks slot activation. | Needed to prevent first-day overbooking during cutover. |

### K.6 P0.0 exit checklist

- [x] Repository/remote, branch, commit, local runtime, and repository-derived architecture reviewed.
- [ ] Actual Hostinger production DB product/version and relevant server settings supplied and verified.
- [ ] Production table engines, final migration state, relevant columns/indexes/FKs/checks, and matching staging capability supplied.
- [ ] Sanitized null-tenant, cross-tenant, missing-reference and duplicate-price counts reviewed; remediation required before relevant constraints.
- [ ] Future operation/resource-assignment reconciliation counts reviewed, with ambiguous capacity conflicts resolved.
- [ ] All business/client decisions affecting P0 pricing, product variants, capacity, resources, timezone, pickup, participants, cancellation, payments and communications recorded.
- [ ] Owner decisions for legacy credential mapping, authorization scope, reservation API contract, retention and all booking paths recorded.
- [ ] Target architecture and rollout/rollback acceptance criteria approved by the project owner; material risks accepted or mitigated.

P0.0 remains **BLOCKED** until every unchecked item is satisfied and verified. P0.1 remains unauthorized even after P0.0 closure; separate implementation approval is still required.
