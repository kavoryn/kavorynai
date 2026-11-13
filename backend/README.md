# Kavoryn PHP API — Stage A foundation

This directory contains the first PHP API entry point for the Android document workflow.

## Current implementation

- `public/index.php` exposes a minimal `GET /v1/health` liveness response.
- Responses include a request ID and no-store/security headers.
- Unknown routes return a stable JSON error envelope.
- No document upload, consent issuance, authentication, OCR provider, or database access is enabled yet.

## Local smoke test

Requires PHP 8.2 or newer.

```sh
php -S 127.0.0.1:8080 -t public
```

In another terminal:

```sh
curl -i http://127.0.0.1:8080/v1/health
```

Expected JSON fields: `status=ok`, `service=kavoryn-api`, and a non-empty `request_id`.

## Deployment rules

- Point the web server document root to `backend/public`, never the repository root.
- Keep `.env`, Composer files, source, logs, and temporary files outside the public web root.
- Require HTTPS before accepting credentials or document data.
- Configure secrets only in the hosting provider's secret manager.
- Do not enable upload or processing endpoints until authentication, server-side consent verification, MIME/signature validation, quotas, temporary-file cleanup, and privacy tests exist.
- The health route is liveness only; it must not expose database or provider status.

## Current status

The health route is a development foundation, not a production-ready API. PHP hosting suitability, queue/background-job support, request-size limits, temporary storage, and deletion behavior still require proof on the selected host.
