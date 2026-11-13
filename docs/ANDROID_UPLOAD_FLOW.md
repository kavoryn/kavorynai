# Android review-to-OCR upload flow

## Implemented interaction sequence

1. User selects a document and reviews local metadata and quality checks.
2. The app requires an active Supabase user session before upload.
3. With a verified HTTPS API origin configured, the app presents a separate OCR upload consent dialog naming the document count and output language.
4. Only the explicit consent callback starts the authenticated submission coordinator.
5. The coordinator requests a short-lived consent grant using the document SHA-256, then streams the file to the upload endpoint with an idempotency key.
6. The app retains the returned document ID and polls the owner-authenticated OCR result endpoint.
7. Polling is bounded to 30 attempts at two-second intervals. Only HTTP 404 is treated as “not ready”; authentication, ownership, validation, and server failures stop immediately.
8. The completed OCR result is validated and shown in the accessible result screen. Uncertain spans are preserved.
9. A separate AI explanation consent dialog is required before calling the selected OpenAI or Gemini explanation endpoint.

## API origin configuration

- Android reads `KAVORYN_API_BASE_URL` from a Gradle property or environment variable and exposes it as `BuildConfig.KAVORYN_API_BASE_URL`.
- Keep it blank until a verified HTTPS staging API origin is supplied. Do not use `kavorynai.com.ng`; that domain is the separate public website, not a confirmed API host.
- Never put provider keys, Supabase secret keys, or service-role credentials in this value or the Android build.

## Cancellation and privacy boundaries

- Selecting a file or reviewing it does not grant OCR or AI consent.
- The OCR consent checkbox and explicit confirmation must be completed before any upload request.
- AI explanation is a separate external-processing step and is never started automatically after OCR.
- “Stop waiting” cancels the client coroutine. It does not claim that an upload already accepted by the server has been deleted or that server processing has stopped.
- Timeout or a lost upload response can be ambiguous; any retry of the same logical submission must reuse the same idempotency key.
- The app does not send privileged credentials to the Android client.

## Automated coverage

- Unit tests verify bounded retry after HTTP 404, successful completion after a pending response, immediate failure on non-404 errors, and rejection of invalid polling bounds.
- Existing OCR result tests verify Unicode code-point to UTF-16 uncertainty-span conversion, including emoji and Arabic text.
- Android CI runs unit tests, assembles a debug APK, and runs the configured Keystore instrumentation tests.

## Remaining acceptance gates

- Supply a verified HTTPS staging API origin and staging test accounts before authenticated end-to-end tests.
- Verify owner isolation, consent rejection, idempotent retry, expiry/deletion, OCR worker completion, and OpenAI/Gemini consent on staging.
- Exercise cancellation and ambiguous network outcomes against the staging server.
- Complete TalkBack, large-text, contrast, reduced-motion, Hausa special-character, and low-RAM real-device testing.
- Keep upload, OCR worker, and Q&A feature flags disabled until these gates pass.
