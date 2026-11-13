# Android review-to-OCR upload flow

## Current boundary

The Android client already contains:
- a local-only document review screen;
- an authenticated, HTTPS-only consent and multipart upload gateway;
- a coordinator that computes a streaming SHA-256 fingerprint, obtains a short-lived consent grant, and uploads only after explicit confirmation;
- an authenticated OCR result reader and accessible result screen.

The new `DocumentProcessingConsentDialog` provides a separate, explicit user decision for OCR upload. It is intentionally distinct from `AiProcessingConsentDialog`, which authorizes sending extracted OCR text to OpenAI or Gemini for explanation.

## Required interaction sequence

1. User selects one or more documents and reviews the local metadata/quality checks.
2. The app requires an active Supabase user session.
3. The app shows the OCR upload consent dialog, naming the number of documents and selected output language.
4. Only the explicit consent callback may invoke `OcrSubmissionCoordinator.submit(..., userConfirmedExternalProcessing = true)`.
5. The coordinator obtains a short-lived consent grant using the document SHA-256, then streams the file to the authenticated upload endpoint with an idempotency key.
6. The app retains the returned document ID and polls the owner-authenticated OCR result endpoint with bounded backoff and a maximum wait.
7. The completed OCR result is shown in `OcrResultScreen`; uncertain spans remain visible and unchanged.
8. A separate AI explanation consent dialog is required before calling the OpenAI/Gemini explanation endpoint.

## Release and privacy gates

- Do not infer OCR consent from sign-in, file selection, or the local review confirmation.
- Never upload before the user checks the explicit consent control and taps Agree and upload.
- Keep upload and OCR worker feature flags disabled until staging acceptance tests pass.
- The Kavorynai website domain is not the staging API URL. Do not substitute it.
- Keep the API base URL unset until the user supplies the staging API endpoint at the final integration stage.
- Never put Supabase service-role, Google Vision, OpenAI, or Gemini secrets in the Android app.
- Cancellation must stop the client request where possible, discard temporary local copies, and must not claim remote deletion unless the API confirms it.
- Treat timeout or lost upload responses as ambiguous; retry only with the same idempotency key.
- Do not start AI explanation automatically after OCR; require its separate provider-specific consent.

## Acceptance tests before enabling the flow

- No session: upload is blocked and the user is directed to sign in.
- Consent unchecked or dialog cancelled: no consent API request and no file bytes leave the device.
- Consent accepted: consent grant is requested before multipart upload.
- Consent endpoint rejection: upload is not attempted.
- Upload retry: same idempotency key is reused for the same logical submission.
- Expired/rejected session: no retry with stale credentials; preserve local document and request sign-in.
- Network timeout: show a safe retry state without exposing tokens, paths, or provider errors.
- OCR pending: bounded polling with backoff; never busy-loop.
- OCR complete: validate document ID and payload, then display original text and uncertain spans.
- Ownership mismatch, malformed response, or oversized response: fail closed.
- User declines AI explanation: OCR text remains available locally without external AI processing.

## Current implementation status

- Explicit OCR upload consent dialog: implemented on the dedicated upload-flow branch.
- Consent coordinator and authenticated gateway: implemented in the existing codebase.
- Review screen wiring, authenticated upload invocation, bounded OCR polling, cancellation/deletion UX, and end-to-end tests: next implementation work.
- Staging API URL and credentials: deliberately deferred to the final integration step.
