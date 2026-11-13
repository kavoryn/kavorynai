# Kavoryn PHP API — Stage A foundation

This directory contains the PHP API entry point for the Android document workflow.

## Implemented endpoints

- `GET /v1/health`: liveness response with request ID.
- `GET /v1/me`: verifies a Supabase Auth user access token and returns the verified user ID.
- `POST /v1/consents`: requires an authenticated user and explicit confirmation, then creates a five-minute, one-time consent token bound to the document SHA-256 digest, action, output language, and translation setting. Only the token hash is persisted.

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

## Security and deployment boundaries

- Point the web server document root to `backend/public`, never the repository root.
- Keep `.env`, Composer files, source, logs, and temporary files outside the public web root.
- Require HTTPS before accepting credentials or document data.
- The health route is liveness only; it must not expose database or provider status.
- Do not accept user IDs supplied by clients as proof of identity.
- The identity endpoint verifies the current user session only. Role authorization, account recovery, phone/email ownership policy, rate limiting, session revocation checks, and account creation UX remain outstanding.
- Do not enable document upload until consent verification, file signature/MIME validation, quotas, temporary-file cleanup, and privacy tests are implemented.

## Current status

Supabase-backed user identity verification is wired into `GET /v1/me`. The `POST /v1/consents` endpoint now issues consent grants through the server-only Supabase adapter. The database stores only a token hash and the one-time consume RPC atomically matches and marks a grant as consumed. Production readiness still depends on configuring server secrets, testing with a real session and real database, HTTPS, rate limiting, and a documented session revocation policy. PHP hosting suitability, queue/background-job support, request-size limits, temporary storage, and deletion behavior also require proof on the selected host.

Secure document upload, OCR, explanation, and TTS are not yet implemented. See `docs/CONSENT_VERIFICATION_DESIGN.md` for the security flow and database prerequisites.
