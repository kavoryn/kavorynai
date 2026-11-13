# Kavoryn — Consent verification implementation

Status: domain logic drafted; persistent adapter and HTTP endpoints are not yet enabled.

## Security flow

1. The Android app authenticates the user. The PHP API resolves the identity through `GET /v1/me`; user IDs from request bodies are never trusted.
2. The app calculates SHA-256 over the selected document by streaming it locally. It displays the exact processing purpose, output language, whether translation is enabled, and that the document will be sent for external processing. The user must actively confirm.
3. The app calls authenticated `POST /v1/consents` with `action`, `payload_sha256`, `language`, `translate`, and `confirmed: true`.
4. The server creates a cryptographically random 256-bit opaque token with a five-minute lifetime. It returns the token once and stores only its SHA-256 hash, along with the authenticated user, action, document digest, language, translation flag, and timestamps.
5. The app uploads the file and token. The server streams the received file to a temporary location, independently computes SHA-256, and compares it with the consent record before processing.
6. Consent is consumed exactly once using one atomic database operation. A read-then-update sequence is not acceptable because two concurrent requests could replay the same grant.
7. Expired, revoked, reused, wrong-user, wrong-action, wrong-language, wrong-translation-setting, or wrong-document grants are rejected. Cancellation before confirmation sends no document bytes.

The token is short-lived and bound to one user, one action, one exact file digest, one output language, and one translation setting. Consent does not grant access to another user's documents and does not replace normal authorization.

## Code added

- `ConsentRecord`: server-side record shape.
- `ConsentGrant`: one-time API response shape.
- `ConsentStore`: persistence contract. Its `consumeOnce` method explicitly requires an atomic implementation.
- `ConsentService`: validates the request, generates a 256-bit opaque token, stores only its hash, and delegates atomic one-time consumption.
- `backend/tests/consent_service_test.php`: tests confirmation, token hashing, expiry, user/document binding, and replay rejection with an in-memory test double.

The in-memory test double is for unit testing only. It is not durable storage and is not used by the API. No consent HTTP endpoint or upload route is enabled yet.

## Existing database review — not approved for production use

The currently connected Supabase project's `public.processing_consents` table has these relevant fields:

- `id`, `user_id`, `feature`, `provider_name`, `purpose`
- `consented_at`, `confirmation_token_hash`, `expires_at`, `revoked_at`

RLS is enabled. The only policy observed is a SELECT policy scoped to `auth.uid() = user_id`. The table's grants include broad privileges for `anon` and `authenticated`, so the RLS policy remains the actual row-level barrier; the table should not be written to directly from the mobile client.

The current table does not yet expose explicit columns for the document SHA-256 digest, output language, translation setting, or consumed timestamp. Those are required to enforce the contract above. Before wiring a persistent adapter, the project/database choice must be confirmed and the schema must be safely migrated with:
- a unique index for token hashes,
- the payload digest and language/translation binding fields,
- a `consumed_at` field,
- an atomic consume operation that matches owner, token hash, action, digest, language, translation flag, expiry, and revocation state,
- least-privilege server access and reviewed RLS/grants.

No database schema or policy was changed during this review.

## Release gates before enabling the endpoint

- Confirm whether this Supabase project is the approved persistent database for Kavoryn.
- Add and review the schema migration and least-privilege server adapter.
- Add integration tests against the real database for atomic replay prevention and cross-user isolation.
- Add authenticated HTTP route tests for missing/false consent and malformed requests.
- Verify the Android confirmation screen and local digest calculation.
- Keep uploads disabled until server-side file hashing and consent consumption are integrated in the same upload workflow.
