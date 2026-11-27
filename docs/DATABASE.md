# Database Design and Migration Safety

## Evidence boundary

The inventory below is **verified from checked-in migrations, models, query code, and tests**, not from live database introspection. Local PHP exposes PDO `mysql` and `sqlite`; an in-memory SQLite connection reports version `3.53.4`. PHPUnit is configured for SQLite `:memory:`. The active local development database engine/version, Hostinger production engine/version, live schema, storage engine, isolation, migration ledger, and staging parity remain **UNVERIFIED — REQUIRES CONFIRMATION**. No live database was connected to for this preparation.

## Verified existing schema relevant to Extra Fun

| Existing table | Verified shape and relationships |
|---|---|
| `tenants` | Tenant identity/status and existing tenant API key prefix/hash, expiry, last-used, and scopes. Existing API credentials are tenant-bound, not company/channel-bound. |
| `companies` | Tenant-owned company records. The tenant migration adds nullable `tenant_id` FK with `nullOnDelete` and backfills existing rows. |
| `currencies` | Shared/global currency table with unique `code`, symbol, exchange rate, default and active flags; it has no tenant ownership in the inspected creation migration. |
| `trips` | Canonical catalog entity; existing fields include name, time, description, status and later region/content fields. `tenant_id` is added by the tenant migration. `operations.id_trip` references `trips`. |
| `prices_company` | Creation migration uses `id_company`, `id_trip`, `id_currency`, `price_adult`, `price_child`, `price_infant`, and `created_at`, with restrictive FKs to companies/trips/currencies. Tenant migration adds nullable `tenant_id` and backfills rows. No company/trip/currency composite unique constraint is shown. |
| `Prices_company` model | `$fillable` lists `company_id`, `trip_id`, `currency_id`, `adult_price`, `child_price`, and related names; default relation keys also differ from the physical migration columns. Direct query-builder callers and the API test fixture use the physical migration names. Repository search found no direct use of this model. This is a verified repository-level mismatch; live schema remains unverified. |
| `extras` | Existing tenant-owned catalog with name, price, tax rate and status. No verified inventory quantity is present in the creation migration. |
| `operation_extras` | Existing operation/extra FKs, price/quantity/amount snapshot, unique `(operation_id, extra_id)`; operation delete cascades, extra delete is restricted. Tenant migration adds nullable `tenant_id`. |
| `operations` | Existing booking record references trip, company, customer, optional trip/hotel, currency and creator; stores trip date/time/pickup, adult/child/infant counts, price/totals/payment and status fields. The customer FK name changes during migration history; the model and current API use `customer_id`. `booking_status` and tenant ownership are additive. |
| `tenant_api_idempotency_keys` | Existing tenant-scoped key hash, request hash, response/status, optional operation FK, and timestamps; unique `(tenant_id, key_hash)`. |
| `operation_status_history` | Existing tenant/operation lifecycle history with actor, reason, and timestamps. |
| `operation_assignments` | Existing operation, boat/vehicle type, untyped `resource_id`, optional driver, and date; uniqueness is per operation/type/resource/date. It is not a time-interval reservation ledger. |

The existing tenant migration adds nullable tenant FKs with `nullOnDelete`, then backfills current rows. That historical pattern is **not** the recommendation for new P0 tables: new tenant-owned rows should require `tenant_id`. Existing tenant columns are not tightened without sanitized data checks. Tenant consistency across new references must be validated transactionally and constrained with composite FKs where existing indexes and the confirmed engine safely permit.

## Proposed P0 migration dependency order

**Proposed only — no migrations have been created or run.** M0 is an evidence gate, not a schema migration.

| Order | Proposed schema group | Key design and dependencies |
|---|---|---|
| **M0** | Sanitized schema baseline | Confirm active local and target/staging engine/version, final columns/FKs/indexes/nullability, migration ledger, tenant integrity counts, and duplicate `prices_company` keys. Do not send credentials or row-level customer data. |
| **M1** | `sales_channels`, `api_clients`, `api_client_sales_channels` | Each client has one non-null tenant and company; each channel has one tenant/company and one required FK to global currency; unique tenant/channel code, API prefix, and client/channel pair. Deactivate/revoke rather than delete referenced records. |
| **M2** | `product_categories`, `trip_variants`, additive `trips` fields | `trips` remains the only product identity. Tenant-scoped public IDs/codes are unique. Category, trip, and variant references restrict deletion when used by bookings. |
| **M3** | Conditional `prices_company` extension; participant tables; `trip_extras` if required | Extend existing pricing only after schema/model and duplicate audit; add no unique price key until existing duplicates are resolved. Add per-person attributes only to the client-approved extent. Reuse `extras` and `operation_extras`. |
| **M4** | `availability_slots`, `slot_resources` | Depends on trip/variant and approved schedules/capacities. New rows require non-null tenant IDs, typed resource FKs, positive capacity, valid half-open time intervals, and tenant/time/resource indexes. Do not store `remaining_capacity`. |
| **M5** | `booking_quotes` and immutable quote lines/snapshots | Depends on API client, channel, pricing, catalog, and slot contract. Tenant/client/channel/company/currency/trip references are required; variant/slot nullable only where contract allows. Quotes create no inventory allocations. |
| **M6** | `booking_reservations`, reservation items, resource allocation items; nullable unique reservation FK on `operations` | Depends on quotes and slots. Unique quote-to-reservation and operation-to-reservation relationships; expiry/status indexes. Keep history and do not cascade-delete business records. |
| **M7** | New API route/auth and legacy compatibility behavior | No new idempotency table by default. Reuse the existing store with a tenant/client/endpoint-scoped derived hash. Keep legacy routes and tenant-key columns until mapped integrations cut over and an owner approves deprecation. |

New business/historical FKs should normally use `RESTRICT` and configuration records should be deactivated rather than deleted. Cascades are appropriate only for pure pivots or dependent records when the approved retention policy permits. Do not add a parallel products, extras, currencies, operations, payments, or idempotency system. Do not alter production or run destructive `down()` migrations.

## Change checklist

- Confirm sanitized live schema, engine/version, migration history, and data assumptions before implementation.
- Reconcile the `prices_company` physical/model discrepancy and audit duplicate/null/cross-tenant rows before touching its schema or adding uniqueness.
- Define exact columns, types, nullability/defaults, foreign keys, deletion behavior, constraints, and indexes for every proposed table.
- Check current data before adding uniqueness, `NOT NULL`, or composite constraints.
- Assess locks, table size, backfill strategy, and compatibility with old/new application versions.
- Use additive, staged migrations; document which rollback paths are safe before and after durable quote/reservation/booking data exists.
- Rehearse on a disposable environment matching the verified target engine/version; SQLite-only tests do not establish production lock behavior.
- Never run production migrations or data operations without explicit human approval.
