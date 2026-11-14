# Vercel preview deployment notes

## Scope

This directory is intended to be the Vercel project Root Directory. The PHP API is exposed through `api/index.php`, which delegates to the existing `public/index.php` router. The PHP runtime is the community runtime `vercel-php@0.5.2`; it is not a first-party Vercel PHP runtime, so a Preview deployment and route smoke test are required before relying on it.

## Vercel project settings

- Root Directory: `backend`
- Framework Preset: `Other`
- Build Command: leave unset unless the runtime's current instructions require one
- Output Directory: leave unset
- Install Command: leave unset for the initial runtime smoke test
- Do not add secrets to Git or to Android build configuration.

## Safe initial environment

Keep all feature flags disabled in Preview until their individual acceptance checks pass:

- `DOCUMENT_UPLOAD_ENABLED=false`
- `OCR_WORKER_ENABLED=false`
- `DOCUMENT_QA_ENABLED=false`
- `DOCUMENT_DELETE_ENABLED=false`
- `CHAT_ENABLED=false`

Configure only the server-side variables required for the health/auth/consent routes, using Vercel's encrypted Environment Variables UI. Never paste secret values into a PR, issue, or chat. Do not enable upload: the current `PrivateDocumentStorage` implementation writes to local disk, which is not durable storage for Vercel Functions. Upload must remain disabled until it is moved to a private Supabase Storage bucket and tested with authorization, retention, and access controls.

## Routes and smoke tests

- `GET /health` and `GET /v1/health` should return the existing JSON health response.
- `GET /v1/me` must return `401` without a valid Supabase access token and must never expose secrets.
- `POST /v1/consents` must remain unavailable until the required server-side Supabase credentials are configured securely; test both missing configuration and a valid consent flow in an isolated Preview environment.

Do not treat a successful health response as proof that upload, OCR, AI explanation, Q&A, or Android integration works.

## Known blockers before enabling features

1. Replace local private document disk storage with private Supabase Storage.
2. Design and deploy the OCR worker on a durable worker/container platform; do not run it as an ordinary short-lived PHP request.
3. Validate the community PHP runtime against the repository's PHP 8.2 code and required extensions.
4. Run backend tests and Preview smoke tests, then Android-to-Preview end-to-end tests.
5. Complete the outstanding migration/security review blockers before production approval.

No production domain, production deployment, database migration, feature-flag change, or merge is authorized by this configuration PR.
