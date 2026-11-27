# API Contracts

Use this document as a coordination source of truth for API changes. Do not document guessed endpoints. Update the contract before parallel frontend/backend work begins; mark proposals versus implemented/verified contracts.

## Contract template

For each endpoint, record:
- Status: PROPOSED / IMPLEMENTED / VERIFIED
- Method and path:
- Purpose:
- Authentication and authorization:
- Request: headers, params, body, validation and examples (no secrets)
- Success response: status and schema/example
- Error responses: status, code/message and validation shape
- Side effects/idempotency:
- Pagination/filtering:
- Compatibility/versioning:
- Owner and tests:

## Existing API inventory

**Status: VERIFIED from current working-tree routes/controllers/tests; not a live-deployment probe.** Routes are in `routes/api.php`; handlers are primarily in `app/Http/Controllers/Api/WebsiteController.php`.

The `/api/v1` route group uses tenant API-key authentication, route scopes, and a 60/minute throttle; booking creation also has a 10/minute throttle. The key remains stored on the tenant and is not persistently bound to a company or sales channel. The current working-tree implementation adds a temporary fail-closed guard to company-sensitive legacy endpoints: exactly one company row must exist for the tenant, and that company must be active. Data/lookups are constrained to that company. Zero, multiple, or inactive-only configurations return `403 legacy_api_company_binding_required`. Booking input must match the resolved company. Shared/global currencies remain available under their existing scope. This is code-level containment, not API-client/channel authorization or a deployed change. Existing booking requests accept `company_id` and `currency_id`.

| Method and path | Current authorization | Current behavior |
|---|---|---|
| `GET /api/documentation`, `GET /api/openapi.yaml` | Public | Serve API documentation/OpenAPI files. |
| `POST /api/webhooks/payments/{provider}` | Throttle plus provider-signature verification | Payment webhook; separate from tenant-key routes. |
| `GET /api/v1/trips`, `GET /api/v1/trips/{trip}` | Tenant key + `trips:read` + exactly-one-company guard | Tenant trip catalog; prices are limited to the single resolved company. |
| `GET /api/v1/companies` | Tenant key + `companies:read` + exactly-one-company guard | The single active company resolved for this legacy key. |
| `GET /api/v1/currencies` | Tenant key + `currencies:read` | Active shared/global currencies. |
| `POST /api/v1/bookings` | Tenant key + `bookings:create` + exactly-one-company guard; optional `Idempotency-Key` | Creates a customer and existing `operations` row; the supplied company must match the resolved company. No capacity reservation exists in the inspected flow. |
| `GET /api/v1/bookings/{booking}` | Tenant key + `bookings:read` + exactly-one-company guard | Reads only a booking belonging to the resolved company. |
| `PATCH /api/v1/bookings/{booking}/status` | Tenant key + `bookings:manage` + exactly-one-company guard | Uses the existing booking lifecycle service after company-scoped lookup. |
| `POST /api/v1/bookings/{booking}/payments` | Tenant key + `payments:create` + exactly-one-company guard | Payment intent lookup is company-scoped; current provider is not configured. |
| `POST /api/v1/payments/{payment}/refund` | Tenant key + `payments:refund` + exactly-one-company guard | Refund lookup verifies the payment's operation belongs to the resolved company; provider behavior remains unconfigured. |

`tests/Feature/WebsiteApiTest.php` contains API key, scope, booking, idempotency, lifecycle, and payment cases. The focused suite was run for the current working-tree increment: **18 tests and 235 assertions passed** using the test suite's in-memory SQLite configuration. This is not a test of the active local database, production, or a deployed build. Existing legacy keys still lack persistent company/channel identity; API-client authorization and channel-scoped idempotency remain future work.

## Extra Fun P0 API proposal

**Status: PROPOSED, not implemented or client-approved.** Existing `/api/v1` legacy routes remain separate. The current working-tree implementation applies the temporary exactly-one-company guard described above; new client-bound endpoints and channel authorization are not implemented.

### Ownership and authorization

- Each `api_client` belongs to exactly one tenant and one company.
- Each `sales_channel` belongs to exactly one tenant and company and resolves exactly one active currency from the existing global `currencies` table.
- One API client may access one or more channels only when they have the same tenant and company as the client. A multi-company integration uses separate clients.
- New routes authenticate an API-client credential, then resolve the authorized channel/company/currency server-side. `company_id` and `currency_id` are not authority selectors; an inconsistent supplied value is rejected.
- Legacy tenant keys continue to authenticate only existing routes during migration, subject to the temporary company guard. Do not copy one legacy key hash to multiple clients. A single mapped integration may retain the existing secret only after explicit owner mapping; otherwise issue a distinct key, overlap for an observed transition, then revoke the old key after owner sign-off.
- Scopes are proposed as `catalog:read`, `availability:read`, `quotes:create/read`, `reservations:create/read`, and `bookings:create/read/manage`. Final scope names require contract approval. Rate limits should key on API client, with optional IP limits.

### Proposed endpoints and lifecycle

| Method and path | Proposed contract |
|---|---|
| `GET /api/v1/catalog/products` | Read visible `trips` and configured variants/categories for the authorized channel. |
| `GET /api/v1/availability` | Read published slots and calculated availability. No hold. |
| `POST /api/v1/booking-quotes` | Calculate and persist an immutable server-side price snapshot. No slot/resource lock or capacity allocation. |
| `GET /api/v1/booking-quotes/{public_id}` | Read only a quote owned by the authenticated client/channel. |
| `POST /api/v1/booking-reservations` | Require an unexpired quote and `Idempotency-Key`; atomically create temporary slot/resource holds. |
| `GET /api/v1/booking-reservations/{public_id}` | Read only the caller's reservation state, expiry, and allocation summary. |
| `POST /api/v1/booking-reservations/{public_id}/booking` | Require a valid unexpired hold and `Idempotency-Key`; create one existing `operations` booking and convert the hold atomically. |

Quotes and reservations have separate persisted expirations; the numeric TTLs require owner/client approval. Expiry is evaluated using server UTC time. Same scoped idempotency key and canonical body replays the same response and original expiry; reuse with a different body returns `409`; replay does not extend an expired hold. One quote may create at most one successful reservation, and a reservation may convert to at most one operation.

The existing `POST /api/v1/bookings` remains unchanged for legacy-only trips. Once a trip is capacity-managed, requests that bypass reservations must return `409 availability_required`. Every staff/API writer must use the same allocation service before such a trip is enabled.

All endpoint details remain proposals until the client/owner decisions and sanitized schema/engine evidence in the blueprint are resolved. No test or implementation status is implied by this contract draft.

## Tenant backup and public ticket contracts

### Tenant backup endpoints

- Status: VERIFIED
- Methods and paths: `POST /api/backup`, `GET /api/backups`, `GET /api/backups/{name}`, `POST /api/restore/{name}`, `POST /api/restore-file`
- Authentication and authorization: authenticated accounting administrator; each file operation is scoped to the initialized tenant.
- Purpose: create, list, download, and restore the active tenant's accounting backups.
- Compatibility: response shapes, snapshot format, and backup names remain unchanged. Backups are stored under a tenant-specific local directory. Snapshot rows that identify another tenant are rejected before creating a safety backup or changing data. Existing files in the former shared root directory remain untouched and are not exposed through tenant endpoints because their ownership cannot be verified.

### Public ticket verification

- Status: VERIFIED
- Method and path: `GET /ticket/{token}`
- Purpose: display a ticket to a bearer of its existing unique, unguessable token.
- Authentication and authorization: anonymous access remains available only through the ticket token; ticket and operation queries use the token's tenant context.
- Compatibility: public links using numeric ticket IDs are no longer supported and return not found. Newly generated ticket QR codes use the token. Authenticated staff PDF and download routes keep their existing paths and tenant-scoped model binding.
