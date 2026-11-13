# Kavoryn — Consent verification implementation

Status: Supabase consent and document registry schema applied; PHP consent issuance and secure upload endpoints implemented. Upload remains disabled by default until host setup and release gates pass.

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
- Tests: service unit tests, Supabase adapter HTTP-contract tests, database invalid-parameter rejection check, and CI syntax/smoke checks.

The endpoint requires server environment variables:
- `SUPABASE_URL`
- `SUPABASE_PUBLISHABLE_KEY`
- `SUPABASE_SECRET_KEY` (server-only API key)
- `SUPABASE_SERVICE_ROLE_KEY` (server-only legacy JWT until the backend migrates to a suitable supported auth mechanism)
- Optional `CONSENT_TTL_SECONDS` (default 300)

Never commit or expose the secret key or service-role JWT to Android, public source, logs, or user documents. The legacy `service_role` JWT is only a temporary compatibility bridge; Supabase has announced end-of-2026 support sunset for legacy `anon`/`service_role` keys. Replace it with a supported dedicated server-side database credential/connection before that deadline and verify PHP host support.

## Secure document upload

`POST /v1/documents` is implemented behind `DOCUMENT_UPLOAD_ENABLED=false`. It requires an authenticated session, multipart file, consent token, output language, translation flag, and an `Idempotency-Key` header.

The server uses `fileinfo` and content parsing rather than trusting the client MIME header or filename. Supported formats are PDF, JPEG, PNG, and WebP. PDF uploads are capped at 20 MiB; image uploads are capped at 10 MiB and 40 megapixels, with a minimum image size of 200×200. The server computes SHA-256 and the private storage layer hashes again while streaming the file to a random filename outside `backend/public`. Directories are set to 0700 and files to 0600; the upload fails closed if those permissions cannot be set.

The `public.documents` table is RLS-enabled and has no client policies or client grants; only the server service role can access it. `register_uploaded_document(...)` is a SECURITY INVOKER RPC with EXECUTE revoked from `public`, `anon`, and `authenticated`. In one database transaction it serializes per-user idempotency, returns an existing matching document on a retry, or atomically consumes the matching one-time consent and inserts a new document row. A missing, expired, mismatched, revoked, or reused consent is rejected. The database never receives the document bytes, only the private storage key and verified metadata.

Unprocessed uploads expire after one hour. `backend/bin/cleanup_expired_documents.php` deletes the private file first and then marks the row deleted; if the database update fails, a later cron run retries the idempotent file deletion. Configure the host cron every five minutes. The endpoint is disabled by default and must remain disabled until the cron and server environment are configured and verified.

Still outstanding: PDF parser/page-count validation, rate limits and quotas, OCR worker/queue integration, successful live authenticated upload testing, and deletion after OCR completion.

## Database migration applied

Supabase project `nncrkjvldpeisyquulic` now has the consent schema and RPC migrations applied: `20261113142411_secure_single_use_document_consents`, `20261113142434_allow_provider_selection_before_consent`, `20261113142706_lock_down_signup_trigger_function`, `20261113142849_create_processing_consent_with_db_clock`, and `20261113142952_validate_all_consent_creation_parameters`.

The `public.processing_consents` table now includes:
- `action` (restricted to `document.process`)
- `payload_sha256` (64-character SHA-256 hex)
- `output_language` (restricted to `en`, `ha`, `ar`, `fr`)
- `translate`
- `consumed_at`

A unique index protects `confirmation_token_hash`. The old client SELECT policy was removed; RLS remains enabled. Table privileges were revoked from `public`, `anon`, and `authenticated`, and granted to `service_role` only.

The migrations add `public.create_processing_consent(...)` and `public.consume_processing_consent(...)`, both SECURITY INVOKER functions. Creation validates all required parameters and calculates consent/expiry timestamps using the database clock. Consumption atomically updates a matching row only if owner, action, digest, language, translation setting, expiry, revocation state, and unused state all match. Execute is revoked from `public`, `anon`, and `authenticated`, and granted to `service_role` only. The database clock is authoritative for expiry.

A second migration, `20261113142434_allow_provider_selection_before_consent`, makes `provider_name` nullable because provider selection may happen after the user gives processing consent.

A third migration, `20261113142706_lock_down_signup_trigger_function`, revokes direct execution of the existing SECURITY DEFINER signup trigger function from `public`, `anon`, and `authenticated`. The trigger remains enabled on `auth.users`; the function is no longer directly callable through the Data API by client roles.

The fourth and fifth migrations, `20261113142849_create_processing_consent_with_db_clock` and `20261113142952_validate_all_consent_creation_parameters`, add and harden the database-clock creation RPC. The database returns the authoritative creation and expiry timestamps, and all required arguments are validated before insertion.

All five consent/auth migrations are recorded in `supabase/migrations/` in this repository. The document registry, idempotency serialization, one-hour expiry, and consent foreign-key index migrations are also recorded there: `20261113143440_secure_document_upload_registry`, `20261113143617_serialize_document_upload_idempotency`, `20261113143907_expire_unprocessed_document_uploads`, and `20261113143947_index_document_consent_foreign_key`. The table had zero rows before the migration, so the new required binding columns were added without migrating existing consent records.

At the time of this implementation, `auth.users` contains zero accounts in this project. The SQL migration and invalid-parameter rejection were verified, but a successful live sign-in and positive one-time-consumption test must wait until the Android sign-up flow creates a test account and the PHP host has its server-only credentials configured.

## Release gates still open

- Configure the server-side secrets in the PHP host; do not put them in Git or Android.
- Test `GET /v1/me` and `POST /v1/consents` with a real signed-in account and the live Supabase project.
- Run database-backed integration tests proving successful one-time consume, replay rejection, and cross-user/digest/language mismatch rejection.
- Add consent revocation endpoint/flow and define session revocation policy.
- Add Android confirmation UI and streaming SHA-256 calculation.
- Configure private storage, HTTPS, PHP upload limits, server secrets and the five-minute cleanup cron on the selected host.
- Complete PDF parser/page-count validation, rate limits and quotas before enabling the upload feature flag.
- Add live authenticated upload tests and verify retry/idempotency behavior with a real Supabase user.
- Keep document upload disabled until the host's private storage, HTTPS, PHP upload limits, five-minute expiry/orphan cleanup cron, PDF page-count validation, rate limits, quotas, and real authenticated integration tests are verified.
