# Kavoryn API on Vercel Preview

## Recommended project configuration

The Vercel project `kavoryn` can keep its Root Directory at the repository root (the default, `.`). The repository-root `Dockerfile.vercel` builds the PHP API from `backend/`, and the root `.dockerignore` limits the container build context to that directory while excluding secrets, tests, and local state.

- Vercel project: `kavoryn`
- Recommended Root Directory: repository root (default)
- Container entry point: `Dockerfile.vercel` at repository root
- PHP source copied into image: `backend/`
- HTTP listener: port `80`
- PHP runtime: FrankenPHP / PHP 8.2
- Document root in container: `/app/public`
- Front controller in repository: `backend/public/index.php`

A second `backend/Dockerfile.vercel` is retained for deployments where the Vercel project Root Directory is explicitly set to `backend`. Both layouts are covered by the Docker smoke workflow. Prefer the repository-root layout so Preview does not depend on changing project Root Directory settings.

The Caddy configuration exposes only `/app/public`. PHP source, tests, environment files, and temporary files remain outside the document root or excluded from the container context. Do not change the separate `kavorynai` Vercel project or the `kavorynai.com.ng` business website.

## Runtime requirements

The image installs the PHP cURL extension and Poppler `pdfinfo` utility, and verifies that PHP `curl`, `fileinfo`, and `sodium` extensions are loaded at build time.

Vercel container instances are stateless. Do not depend on local disk for user documents, OCR results, sessions, or durable job queues. Use the configured private Supabase Storage and database adapters; keep background OCR work on a durable worker host. No user document should be placed in the container image.

## Preview safety

The container smoke workflow requires no secrets. It builds both supported Dockerfile locations and verifies that:
- `GET /v1/health` returns JSON with HTTP 200.
- `GET /` reaches the PHP API router and returns a JSON 404, never PHP source.
- `GET /v1/me` fails closed with HTTP 503 when Auth configuration is absent.
- Backend source files are not served from the public document root.

For subsequent authenticated Preview integration, configure only the necessary variables in the Vercel `kavoryn` project's **Preview** environment, never Production. Use Vercel's encrypted Environment Variables UI; do not put values in Git, Docker build arguments, logs, APKs, or chat.

Keep all feature flags disabled until their individual staging gates pass:

- `CHAT_ENABLED=false`
- `DOCUMENT_UPLOAD_ENABLED=false`
- `OCR_WORKER_ENABLED=false`
- `DOCUMENT_QA_ENABLED=false`
- `DOCUMENT_DELETE_ENABLED=false`

A successful container health check is not proof of authenticated upload, OCR, AI explanation, Q&A, secure deletion, Android integration, or release readiness. Those require isolated test accounts/documents and explicit end-to-end evidence.

## Safety boundary

This change is intended for a Draft PR targeting `integration/preview`. It does not change Vercel project settings, environment variables, Supabase schema/data/policies, feature flags, Production domains, or the separate business website. Do not merge until the container Preview and security review are accepted.
