# Vercel Preview deployment notes

## Scope

This directory is the Vercel project Root Directory. The PHP API is served by the official FrankenPHP-based container image defined in `Dockerfile.vercel`. The container's Caddy configuration routes requests to the existing `public/index.php` front controller; the existing PHP router remains the single source of truth.

The previous `vercel-php@0.5.2` community runtime has been removed. It failed because it depended on discontinued Node.js 14, and the community runtime repository now recommends container deployment for new projects.

## Vercel project settings

- Root Directory: `backend`
- Framework Preset: `Other` (or leave as auto-detected if Vercel selects container deployment)
- Build Command: leave unset
- Output Directory: leave unset
- Install Command: leave unset; dependencies are not required by the current API bootstrap
- Container entry point: `Dockerfile.vercel`
- Container HTTP listener: port `80`

Do not add secrets to Git, Docker build arguments, image layers, or Android build configuration.

## Safe initial environment

Keep all feature flags disabled in Preview until their individual acceptance checks pass:

- `CHAT_ENABLED=false`
- `DOCUMENT_UPLOAD_ENABLED=false`
- `OCR_WORKER_ENABLED=false`
- `DOCUMENT_QA_ENABLED=false`
- `DOCUMENT_DELETE_ENABLED=false`

Configure only the server-side variables required for the health/auth/consent routes, using Vercel's encrypted Environment Variables UI. Never paste secret values into a PR, issue, or chat.

Do not enable upload: the current `PrivateDocumentStorage` implementation writes to local disk, which is not durable storage for stateless Vercel container instances. Upload must remain disabled until it is moved to a private Supabase Storage bucket and tested with authorization, retention, and access controls.

## Routes and smoke tests

- `GET /health` and `GET /v1/health` should return the existing JSON health response.
- `GET /v1/me` must return `401` without a valid Supabase access token and must never expose secrets.
- `POST /v1/consents` must remain unavailable until the required server-side Supabase credentials are configured securely; test both missing configuration and a valid consent flow in an isolated Preview environment.

A successful health response is not proof that upload, OCR, AI explanation, Q&A, or Android integration works.

## Known blockers before enabling features

1. Replace local private document disk storage with private Supabase Storage.
2. Design and deploy the OCR worker on a durable worker/container platform; do not run it as an ordinary short-lived request.
3. Validate the FrankenPHP container build, PHP 8.2 runtime, and cURL extension in Preview.
4. Run backend tests and Preview smoke tests, then Android-to-Preview end-to-end tests.
5. Complete the outstanding migration/security review blockers before production approval.

No production domain, production deployment, database migration, feature-flag change, or merge is authorized by this configuration PR.
