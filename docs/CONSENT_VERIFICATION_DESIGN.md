# Kavoryn — Consent verification implementation

Status: Supabase schema migration applied; PHP consent issuance endpoint and persistent adapter implemented. Secure upload remains disabled.

## Security flow

1. Android authenticates the user. PHP resolves identity through Supabase Auth; user IDs from request bodies are never trusted.
2. Android calculates SHA-256 over the selected document by streaming it locally. It displays the processing purpose, output language, translation setting, external processing disclosure, and delete-after-use default. The user must actively confirm.
3. Android calls authenticated `POST /v1/consents` with `action`, `payload_sha256`, `language`, `translate`, and `confirmed: true`.
4. PHP creates a cryptographically random 256-bit opaque token with a five-minute default lifetime. The database stores only its SHA-256 hash, bound to the authenticated user, action, document digest, output language, translation flag, expiry, and timestamps.
5. The app uploads the file and token only after confirmation. The future upload handler must independently hash the received bytes and compare the digest with the grant.
6. The future upload handler must consume consent using the atomic database RPC. A read-then-update sequence is not acceptable because concurrent requests could replay a grant.
7. Expired, revoked, reused, wrong-user, wrong-action, wrong-language, wrong-translation-setting, or wrong-document grants are rejected.

The consent endpoint does not accept a user ID from the client. Consent does not grant access to another user's documents and does not replace normal authorization.

## Implemented code

- `ConsentService`: validates consent input, generates the 256-bit opaque token, stores only its hash, and sets a bounded TTL (60–600 seconds; default 300).
- `SupabaseConsentStore`: server-only persistence adapter. It sends the new Supabase secret API key in `apikey` and the legacy `service_role` JWT in the Authorization bearer. The new `sb_secret` API key is not a JWT and must not be used as a bearer token.
- `POST /v1/consents`: authenticated JSON endpoint. It derives the user ID from verified Supabase Auth, requires explicit boolean confirmation, validates request shape, and returns the opaque token once.
- `ConsentStore`: persistence contract. Consumption is delegated to a single atomic database function.
- Tests: service unit tests, Supabase adapter HTTP-contract tests, and CI syntax/smoke checks.

The endpoint requires server environment variables:
- `SUPABASE_URL`
- `SUPABASE_PUBLISHABLE_KEY`
- `SUPABASE_SECRET_KEY` (server-only API key)
- `SUPABASE_SERVICE_ROLE_KEY` (server-only legacy JWT until the backend migrates to a suitable supported auth mechanism)
- Optional `CONSENT_TTL_SECONDS` (default 300)

Never commit or expose the secret key or service-role JWT to Android, public source, logs, or user documents.

## Database migration applied

Supabase project `nncrkjvldpeisyquulic` now has migration `20261113142411_secure_single_use_document_consents` applied.

The `public.processing_consents` table now includes:
- `action` (restricted to `document.process`)
- `payload_sha256` (64-character SHA-256 hex)
- `output_language` (restricted to `en`, `ha`, `ar`, `fr`)
- `translate`
- `consumed_at`

A unique index protects `confirmation_token_hash`. The old client SELECT policy was removed; RLS remains enabled. Table privileges were revoked from `public`, `anon`, and `authenticated`, and granted to `service_role` only.

The migration adds `public.consume_processing_consent(...)`, a SECURITY INVOKER SQL function that atomically updates a matching row only if owner, action, digest, language, translation setting, expiry, revocation state, and unused state all match. Execute is revoked from `public`, `anon`, and `authenticated`, and granted to `service_role` only. The function uses the database clock as the source of truth.

A second migration, `20261113142434_allow_provider_selection_before_consent`, makes `provider_name` nullable because provider selection may happen after the user gives processing consent.

Both migrations are recorded in `supabase/migrations/` in this repository. The table had zero rows before the migration, so the new required binding columns were added without migrating existing consent records.

## Release gates still open

- Configure the server-side secrets in the PHP host; do not put them in Git or Android.
- Test `GET /v1/me` and `POST /v1/consents` with a real signed-in account and the live Supabase project.
- Run database-backed integration tests proving successful one-time consume, replay rejection, and cross-user/digest/language mismatch rejection.
- Add consent revocation endpoint/flow and define session revocation policy.
- Add Android confirmation UI and streaming SHA-256 calculation.
- Implement secure upload so server-side file hashing and atomic consent consumption happen in the same upload workflow.
- Keep document upload disabled until MIME/signature validation, size/page limits, temporary storage, deletion, and privacy tests are implemented.
