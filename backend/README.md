# Kavoryn PHP API — Stage A foundation

This directory contains the PHP API entry point for the Android document workflow.

## Implemented endpoints

- `GET /v1/health`: liveness response with request ID.
- `GET /v1/me`: verifies a Supabase Auth user access token and returns the verified user ID.
- `POST /v1/consents`: requires an authenticated user and explicit confirmation, then creates a short-lived, one-time consent token bound to the document SHA-256 digest, action, output language, and translation setting. Only the token hash is persisted.
- `POST /v1/documents`: accepts an authenticated multipart upload, inspects actual file type/signature, validates size and image dimensions, uses Poppler `pdfinfo` to reject damaged/encrypted PDFs and enforce a 50-page maximum, computes SHA-256 on the server, applies atomic per-user upload limits (5 per minute and 30 per hour), stores the file outside the public web root, and atomically consumes matching consent while registering the document in Supabase. Returns `201` with status `uploaded`; OCR/job processing is not yet connected.

The identity endpoint calls Supabase Auth's `/auth/v1/user` endpoint. It does not trust a user ID from request data or decode unverified JWT claims. It requires:

- `SUPABASE_URL`
- `SUPABASE_PUBLISHABLE_KEY`

Set these in the server's secret/configuration manager. `SUPABASE_PUBLISHABLE_KEY` is for Auth identity verification. Consent persistence uses `SUPABASE_SECRET_KEY` as the `apikey` and the legacy `SUPABASE_SERVICE_ROLE_KEY` JWT as the Authorization bearer; the secret key cannot itself be used as a JWT bearer. Both elevated values are server-only and must never enter Android, Git, logs, or public documents. The legacy `service_role` JWT is a compatibility bridge only: Supabase plans to end support for legacy `anon`/`service_role` keys at the end of 2026. Before then, replace this bearer mechanism with a supported dedicated server-side database connection/credential approach and test it on the selected PHP host. PHP cURL with TLS verification is required.

Example request:

```http
GET /v1/me
Authorization: Bearer <Supabase user access token>
```

A valid session receives `authenticated: true` and a server-verified `user_id`. Missing server configuration returns `503 authentication_not_configured`; a missing, invalid, or rejected access token returns `401 unauthorized`. The bearer token and raw Auth response must never be logged.

## Local smoke test

Requires PHP 8.2 or newer.

```sh
cd backend
php -S 127.0.0.1:8080 -t public
```

In another terminal:

```sh
curl -i http://127.0.0.1:8080/v1/health
curl -i http://127.0.0.1:8080/v1/me
```

Without server-side Supabase configuration, `/v1/me` must fail closed with HTTP 503. To test a real signed-in user, configure the Auth variables and send a current Supabase user access token. `POST /v1/consents` additionally requires the server-only database keys and an authenticated JSON request containing `action`, `payload_sha256`, `language`, `translate`, and `confirmed: true`.

## Secure upload request

The route expects `multipart/form-data` with a `file`, `consent_token`, `output_language` (`en`, `ha`, `ar`, or `fr`), and `translate` (`true` or `false`). Send the retry key as an `Idempotency-Key` header:

```sh
curl -X POST "$API_BASE_URL/v1/documents" \
  -H "Authorization: Bearer $SUPABASE_ACCESS_TOKEN" \
  -H "Idempotency-Key: $UPLOAD_IDEMPOTENCY_KEY" \
  -F "file=@document.pdf" \
  -F "consent_token=$CONSENT_TOKEN" \
  -F "output_language=ha" \
  -F "translate=false"
```

The endpoint returns `201` and status `uploaded`; it does not yet enqueue OCR. A definite consent or idempotency conflict returns `409`. A transient registry failure returns `503`; retry using the same idempotency key. The feature flag remains off until the host release gates are complete. The Supabase-backed rate limiter fails closed if its RPC is unavailable and returns HTTP 429 with `Retry-After` when an account reaches either limit.

## Security and deployment boundaries

- Point the web server document root to `backend/public`, never the repository root.
- Keep `.env`, Composer files, source, logs, and temporary files outside the public web root.
- Require HTTPS before accepting credentials or document data.
- The health route is liveness only; it must not expose database or provider status.
- Do not accept user IDs supplied by clients as proof of identity.
- The identity endpoint verifies the current user session only. Role authorization, account recovery, phone/email ownership policy, general API-wide rate limiting (the document upload route has its own limiter), session revocation checks, and account creation UX remain outstanding.
- Upload requires `PRIVATE_DOCUMENT_STORAGE_PATH` (an absolute private directory outside `backend/public`) and `DOCUMENT_UPLOAD_ENABLED=false` by default. It remains disabled until release gates pass. The database sets an expiry of one hour for unprocessed uploads. `backend/bin/cleanup_expired_documents.php` deletes expired private files and marks their rows deleted; schedule it every five minutes using the host cron service. Do not enable upload until server secrets and private storage are configured, host upload limits are raised appropriately, the Poppler `pdfinfo` binary is installed and reachable through `PDFINFO_BINARY` (or `PATH`), the Supabase upload-rate-limit migrations are applied, and the cleanup cron is configured and tested.

## Current status

Supabase-backed user identity verification is wired into `GET /v1/me`. The `POST /v1/consents` endpoint now issues consent grants through the server-only Supabase adapter. The database stores only a token hash and the one-time consume RPC atomically matches and marks a grant as consumed. Production readiness still depends on configuring server secrets, testing with a real session and real database, HTTPS, rate limiting, and a documented session revocation policy. PHP hosting suitability, queue/background-job support, request-size limits, temporary storage, and deletion behavior also require proof on the selected host.

Secure upload foundations and `POST /v1/documents` are implemented in code. The Supabase `documents` table is private to the service role, and `register_uploaded_document` atomically consumes consent and registers the file with per-user idempotency. The endpoint is deliberately disabled by default. Do not set `DOCUMENT_UPLOAD_ENABLED=true` until the PHP host has an absolute private storage path, HTTPS, PHP `fileinfo`/cURL support, `upload_max_filesize` of at least 20M and `post_max_size` of at least 22M, plus rate limits, quota enforcement, the `pdfinfo` PDF page-count validation, the five-minute cleanup cron, and a real authenticated integration test. The endpoint currently returns `uploaded`; it does not enqueue OCR yet. See `docs/CONSENT_VERIFICATION_DESIGN.md` and `docs/STAGE_A_API_CONTRACT.md`.


## Expired upload cleanup

Unprocessed uploads receive a one-hour expiry in Postgres. Configure the PHP host to run this command every five minutes, with the same server-only environment variables available to the API:

```cron
*/5 * * * * /usr/bin/php /home/ACCOUNT/backend/bin/cleanup_expired_documents.php >/dev/null 2>&1
```

Replace `/home/ACCOUNT` and the PHP binary path with the actual hosting paths. The cleanup script removes the private file first and then marks the database row deleted. It also scans for old staged files that have no matching database row, which covers interrupted registration requests. If the database update fails, the next run safely retries because deleting a missing file is treated as success. The script never reads or logs document contents.


## PDF validation and upload throttling

PDF uploads require Poppler's `pdfinfo` utility on the PHP host. Install the Poppler utilities package using the host's supported package manager, or set `PDFINFO_BINARY` to the absolute path of the trusted `pdfinfo` executable. The API invokes it without a shell, with an eight-second timeout, and fails closed when it is missing or cannot inspect the file. Password-protected, damaged, zero-page, and over-50-page PDFs are rejected. Images do not require Poppler.

The database migrations `document_upload_rate_limit` and `cleanup_document_upload_rate_limits` create a private, RLS-protected counter store and service-role-only RPCs. The atomic limiter permits up to 5 document upload attempts per account per minute and 30 per account per hour. A blocked attempt receives HTTP 429 and a `Retry-After` header. The PHP endpoint calls this RPC after authentication and request-shape checks but before document inspection/storage. If the rate-limit service is unavailable, uploads fail closed with HTTP 503.

These are abuse-protection limits, not subscription entitlements or advertised customer plan quotas. They should be reviewed against expected traffic before launch. The existing five-minute document cleanup cron also invokes the service-role-only rate-limit cleanup RPC and removes counter buckets older than two days.


## OCR job queue foundation

New document registrations with status `uploaded` are automatically added to the private `private.ocr_jobs` queue by a database trigger. Queue rows are not exposed to app clients. The worker-only claim RPC uses row locking with `SKIP LOCKED`, a bounded lease, and a maximum of three attempts; expired leases can be reclaimed. The worker can record a bounded error code and retry delay, or complete a job only while its worker lease is still valid.

The queue currently provides job orchestration only. No OCR vendor is selected or called, no OCR text is stored, and the upload endpoint does not yet start a provider request. The PHP worker, authenticated job-status endpoint, provider adapter, and end-to-end OCR integration remain to be implemented. Keep the document upload feature flag disabled until the full flow is deployed and tested.


## PHP OCR worker orchestration

The backend now contains a provider-independent worker core under `backend/src/Ocr/`:

- `OcrJob` validates queue metadata and storage keys before processing.
- `SupabaseOcrJobStore` calls the existing private queue claim, fail, and completion RPCs using server-only credentials over verified HTTPS.
- `OcrWorker` resolves only generated storage keys inside the configured private storage root, invokes the injected OCR provider, persists results through an injected result sink, and completes the lease only after persistence succeeds. Failures are reduced to bounded error codes with exponential retry delay.
- `OcrWorkerTest.php` covers successful processing, persistence-before-completion, missing private files, and empty queues. It is included in the PHP API workflow.

This is orchestration code, not a runnable production worker yet. A concrete OCR provider adapter and an approved result sink/retention policy must be selected and configured through a server-only bootstrap before the worker can claim jobs. Do not mark jobs successful or enable uploads without durable result storage. No document text is logged by this worker core.


## Google Cloud Vision OCR and encrypted result delivery

The first provider adapter is Google Cloud Vision `DOCUMENT_TEXT_DETECTION`. Its published OCR language list identifies Arabic, English, and French as supported languages; Hausa (`ha`) is listed as a mapped language using the Latin character model, not as a regularly evaluated supported language. The app must therefore show uncertain words and Hausa accuracy must be measured against a manually verified fixture set before production release.

The adapter omits language hints so Vision can auto-detect mixed-language content. For PDFs, the worker uses local Poppler `pdfinfo` and `pdftoppm` to render up to 50 pages into temporary JPEG images and submits bounded synchronous image batches (maximum 16 images per request and a conservative JSON payload cap). This avoids uploading user PDFs to a second cloud bucket. Temporary page images are removed after processing.

The worker uses a Google service-account JSON file whose permissions must not be broader than owner-only. OAuth JWTs are signed server-side with OpenSSL; access tokens are cached only for the life of the worker process. No Google credential or Supabase service credential is sent to Android.

### Server-only configuration

Set these variables in the backend host environment (never in Android, the web client, or a committed file):

- `GOOGLE_SERVICE_ACCOUNT_JSON_PATH`: absolute path to the Google service-account JSON file outside the public web root; file mode `0600`.
- `OCR_RESULT_ENCRYPTION_KEY`: base64 encoding of exactly 32 cryptographically random bytes (generate with `openssl rand -base64 32`).
- `OCR_RESULT_ENCRYPTION_KEY_VERSION=1`.
- `OCR_WORKER_ENABLED=true` only after all provider and storage settings have been verified.
- `OCR_WORKER_MAX_JOBS`: optional 1–10 jobs per CLI invocation (default 3).
- Existing `SUPABASE_URL`, `SUPABASE_SECRET_KEY`, `SUPABASE_SERVICE_ROLE_KEY`, and `PRIVATE_DOCUMENT_STORAGE_PATH` values are also required.

The host needs PHP cURL, OpenSSL, sodium, Poppler `pdfinfo` and `pdftoppm`, plus network access to Google OAuth, Cloud Vision, and the Supabase HTTPS API.

Run the worker from the CLI only:

```bash
php backend/bin/ocr_worker.php
```

Schedule the command using the hosting provider's cron facility after staging tests. The worker is disabled unless `OCR_WORKER_ENABLED=true`. Keep `DOCUMENT_UPLOAD_ENABLED=false` until the full upload → queue → OCR → encrypted result → authenticated result-read flow passes staging tests.

### Result privacy and retrieval

OCR JSON (text, uncertainty spans, and detected language) is encrypted in PHP using libsodium `crypto_secretbox` with a fresh 24-byte nonce for every result. Supabase stores only ciphertext, nonce, a keyed HMAC, counts, provider/version metadata, and expiry in `private.ocr_results`. The encryption key stays in the backend environment. The database table has RLS enabled, no client policies, and no `anon` or `authenticated` grants.

Worker and result RPCs are exposed through `public` only as narrowly granted wrappers: execution is revoked from `PUBLIC`, `anon`, and `authenticated`, and granted to `service_role` only. The authenticated API endpoint `GET /v1/documents/{document_id}/result` passes the verified user ID to the owner-checking RPC, decrypts on the server, verifies the keyed HMAC and result metadata, and returns `Cache-Control: no-store` JSON.

Results are temporary, not a saved document library. Their expiry is copied from the source document's one-hour processing expiry. The document cleanup CLI purges expired OCR results as well as expired document files and rate-limit buckets. A separate long-term “Save” feature must require a separate user opt-in and retention policy.

### Current integration boundary

The provider adapter, service-account token exchange, worker CLI, encrypted result sink/reader, and queue wrappers are implemented. Automated tests mock Google and Supabase HTTP responses; they do not prove live Google OCR accuracy or production credentials. Hausa benchmark accuracy, staging end-to-end verification, provider billing/quotas, and the final upload/worker enablement gate remain outstanding.
