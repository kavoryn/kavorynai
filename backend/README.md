# Kavoryn PHP API — Stage A foundation

This directory contains the PHP API entry point for the Android document workflow.

## Implemented endpoints

- `GET /v1/health`: liveness response with request ID.
- `GET /v1/me`: verifies a Supabase Auth user access token and returns the verified user ID.
- Consent domain classes define short-lived, document-digest-bound, single-use grants behind a persistence interface. No HTTP consent or upload route is enabled until a durable store is approved and implemented.

The identity endpoint calls Supabase Auth's `/auth/v1/user` endpoint. It does not trust a user ID from request data or decode unverified JWT claims. It requires:

- `SUPABASE_URL`
- `SUPABASE_PUBLISHABLE_KEY`

Set both in the server's secret/configuration manager. A publishable key is intended for API identification; do not put a Supabase secret/service-role key in the Android app or use it for this check. PHP cURL with TLS verification is required.

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

Without server-side Supabase configuration, `/v1/me` must fail closed with HTTP 503. To test a real signed-in user, configure the two server variables and send a current Supabase user access token.

## Security and deployment boundaries

- Point the web server document root to `backend/public`, never the repository root.
- Keep `.env`, Composer files, source, logs, and temporary files outside the public web root.
- Require HTTPS before accepting credentials or document data.
- The health route is liveness only; it must not expose database or provider status.
- Do not accept user IDs supplied by clients as proof of identity.
- This endpoint verifies identity only. It does not yet implement roles, account recovery, phone/email ownership policy, rate limiting, session revocation checks, or account creation UX.
- Do not enable document upload until consent verification, file signature/MIME validation, quotas, temporary-file cleanup, and privacy tests are implemented.

## Current status

Supabase-backed user identity verification is wired into `GET /v1/me`. Consent grant domain logic and an atomic persistence interface are drafted and unit-test coverage is added, but no durable consent store or HTTP consent endpoint is enabled. Production readiness still depends on configuring Supabase, testing with a real session, HTTPS, rate limiting, and a documented session revocation policy. PHP hosting suitability, queue/background-job support, request-size limits, temporary storage, and deletion behavior also require proof on the selected host.

Secure document upload, OCR, explanation, and TTS are not yet implemented. See `docs/CONSENT_VERIFICATION_DESIGN.md` for the security flow and database prerequisites.
