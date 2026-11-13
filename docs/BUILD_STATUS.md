# Build status

Last updated: 2026-11-13

## Repository baseline

- Product requirements from Kavoryn Project Plan v3.1 are transcribed into `docs/PRODUCT_REQUIREMENTS_V3.1.md`.
- Stage A architecture, API contract, local intake design and test plan are recorded under `docs/`.
- Native Android Kotlin + Jetpack Compose app lives in `app/`.
- PHP API foundation and provider adapters live under `backend/`.
- Supabase project is used for private OCR jobs, encrypted OCR results and consent audit records.

## Implemented foundations

### Android

- Camera capture to app-private cache, document picker for PDF/JPEG/PNG/WebP, local metadata and structure checks, image rotation, bounded preview, and stale temporary scan cleanup.
- Local review-before-process screen. Confirming this screen is still local-only; it does not itself upload a document.
- Provider-neutral OCR contracts for English, Hausa, Arabic and French, including per-page text, uncertainty spans, confidence and geometry validation.
- Consent-before-upload coordinator and authenticated HTTPS gateway for `POST /v1/consents` and multipart `POST /v1/documents`. Upload streams data and uses idempotency keys.
- Authenticated HTTPS client for completed OCR results, Unicode code-point to UTF-16 uncertainty range conversion, and an accessible OCR text review screen.
- AI processing consent dialog with OpenAI/Gemini and language selection, plus authenticated explanation API client.
- Supabase Auth REST client supports password sign-in, refresh-token exchange and sign-out over HTTPS using an injected client publishable key.
- Supabase access/refresh tokens can be stored using AES-256-GCM encryption backed by an Android Keystore key; corrupt or undecryptable session data is cleared.
- Supabase sign-in and email/password registration are wired into the main Compose activity. Registration supports both immediate sessions and email-confirmation-required projects. Successful authentication persists the session in Android Keystore-backed encrypted storage; startup restores the session and refreshes it when near expiry; sign-out revokes remotely when possible and always clears the local session. Network and keystore operations run on `Dispatchers.IO`.
- Local document selection/review remains available without an account. Registration and proactive background refresh scheduling are not implemented.
- Unit tests cover Supabase project URL validation and auth-session parsing.
- Android CI runs unit tests before assembling the debug APK.

### Backend

- PHP authentication/consent, secure upload and OCR processing foundations.
- Google Cloud Vision adapter supports English, Arabic, French and Hausa; uncertainty metadata is retained.
- OCR results are encrypted at rest and read only through an owner-authenticated endpoint.
- Private OCR queue, bounded worker leases/retries, cleanup schedule and short-lived result retention are implemented.
- OpenAI and Gemini explanation adapters are selected explicitly; no silent provider fallback.
- `POST /v1/documents/{document_id}/explain` requires authenticated, document-specific AI processing consent and records the consent audit before external processing.
- Feature flags for document upload and OCR worker remain disabled pending staging verification.

## Verified build evidence

- Android workflow [43479920282](https://github.com/kavoryn/kavorynai/actions/runs/43479920282) completed successfully on the Supabase Auth foundation; unit tests and debug APK assembly passed.
- Earlier Android workflows [43475751689](https://github.com/kavoryn/kavorynai/actions/runs/43475751689) and [43476229668](https://github.com/kavoryn/kavorynai/actions/runs/43476229668) also passed their respective commits.

A successful CI build is not a staging or device acceptance test.

## Not yet complete / release blockers

- Supabase URL and publishable key are injected from Gradle properties or environment variables (`SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY`); never place a service-role key in Android or source control.
- Proactive background token refresh scheduling, session-expiry UX and encrypted-store device tests remain outstanding.
- Staging API base URL and staging credentials have not been supplied, so authenticated end-to-end staging tests have not run.
- The local review screen is not yet connected to authenticated upload, OCR completion polling, OCR result display, AI explanation consent/API, and result actions.
- Text-to-speech, document-grounded Q&A, Share and Delete controls are not yet connected end-to-end in the Android result flow.
- OCR quality, Hausa special-character accuracy, multi-page provider behavior, and key-detail grounding still require a fixed permissioned/synthetic corpus and measured results.
- TalkBack, low-vision, large-text, contrast, reduced-motion and low-RAM real-device acceptance tests remain outstanding.
- No production deployment is authorized or claimed. Do not modify the separate Kavorynai website/domain as part of this app work.

## Next engineering milestones

1. Run CI for the integrated Supabase sign-in/session flow and resolve any failures.
2. Add proactive refresh/session-expiry handling with device-level secure-storage tests.
3. Connect Android review → consent → upload → OCR result → AI explanation, with cancel/delete and explicit consent behavior.
4. Configure and test a staging environment, then verify ownership isolation, expiry/deletion, retries, provider consent, OpenAI and Gemini.
5. Complete accessibility and multilingual corpus testing before enabling upload/worker flags or any public release.

## Release discipline

A feature is complete only after implementation and its relevant security, privacy, accuracy and accessibility tests pass. Keep upload and OCR worker flags disabled until staging gates pass. Never expose provider keys or privileged database credentials in Android or source control. Keep the existing unrelated production website unchanged.
