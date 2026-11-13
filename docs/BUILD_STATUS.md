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
- Supabase email/password sign-in and registration, encrypted Android Keystore-backed session storage, startup session restoration, refresh handling, and sign-out.
- Explicit OCR consent, authenticated HTTPS upload coordinator, idempotency key, bounded owner-authenticated OCR result polling, and OCR result display.
- Separate provider-specific AI consent before the authenticated OpenAI/Gemini explanation endpoint.
- OCR result validation and Unicode code-point to UTF-16 uncertainty range conversion.
- Unit tests cover auth parsing/session policies, OCR range conversion, and bounded OCR polling behavior.
- Android CI runs unit tests, debug APK assembly, and configured Keystore instrumentation tests.

### Backend

- PHP authentication/consent, secure upload and OCR processing foundations.
- Google Cloud Vision adapter supports English, Arabic, French and Hausa; uncertainty metadata is retained.
- OCR results are encrypted at rest and read only through an owner-authenticated endpoint.
- Private OCR queue, bounded worker leases/retries, cleanup schedule and short-lived result retention are implemented.
- OpenAI and Gemini explanation adapters are selected explicitly; no silent provider fallback.
- `POST /v1/documents/{document_id}/explain` requires authenticated, document-specific AI processing consent and records the consent audit before external processing.
- Document-grounded Q&A backend and Android Q&A building blocks exist; the Q&A screen is not yet connected to the completed document result flow.
- Upload, OCR worker and Q&A feature flags remain disabled pending staging verification.

## Latest merged Android integration

- PR #10, [Android review → consent → upload → OCR result → AI explanation](https://github.com/kavoryn/kavorynai/pull/10), merged to `main`.
- Merge commit: `ea33c3ead85ab83fa630d994a4e57425af5493c8`.
- CI for the PR head completed successfully, including Android unit tests, debug APK assembly, and Keystore instrumentation tests.
- The API origin remains deliberately unset until a verified HTTPS staging endpoint is supplied.

A successful CI build is not a staging or real-device acceptance test.

## Remaining release blockers

- Staging API base URL and staging credentials have not been supplied, so authenticated end-to-end staging tests have not run.
- Background WorkManager refresh across app process/background states, polished session-expiry UX and encrypted-store device tests remain outstanding.
- Android document-grounded Q&A, text-to-speech controls, Share and Delete actions are not yet connected end-to-end in the result flow.
- OCR quality, Hausa special-character accuracy, multi-page provider behavior, and key-detail grounding require a fixed permissioned/synthetic corpus and measured results.
- TalkBack, low-vision, large-text, contrast, reduced-motion and low-RAM real-device acceptance tests remain outstanding.
- No production deployment is authorized or claimed. Do not modify the separate Kavorynai website/domain as part of this app work.

## Next engineering milestones

1. Strengthen automated Android tests for the consent/upload/result/explanation flow and keep documentation aligned with implementation.
2. Complete Android result actions, including document-grounded Q&A, text-to-speech, Share and Delete with explicit privacy behavior.
3. Configure and test staging, then verify ownership isolation, expiry/deletion, retries, provider consent, OpenAI and Gemini.
4. Complete background session refresh, expiry UX, encrypted-store device tests, accessibility and multilingual corpus testing before enabling upload/worker flags or any public release.

## Release discipline

A feature is complete only after implementation and its relevant security, privacy, accuracy and accessibility tests pass. Keep upload and OCR worker flags disabled until staging gates pass. Never expose provider keys or privileged database credentials in Android or source control. Keep the existing unrelated production website unchanged.
