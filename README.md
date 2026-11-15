# Kavoryn

Kavoryn is an accessibility-first application that helps people read, understand, and listen to documents in their chosen language. The authoritative product requirements are in Kavoryn Project Plan v3.1 (12 November 2026).

## Delivery stages

- Stage A: document scan/upload, multi-page OCR, uncertainty flags, grounded explanations (Summary, Detailed, What to do), key details, language selection, text-to-speech, document-grounded Q&A, consent review, delete-after-use, and accessibility.
- Stage B: advanced voice dictation, voice input in Chat, and document preparation helper.
- Stage C: scene/object description, money/currency reading, and colour/light utilities. This stage must pass its accuracy and TalkBack gates before release.

## Current implementation status

- Android document intake, local PDF/image inspection, camera capture, image rotation and bounded local preview are implemented as foundations; real-device/build acceptance remains outstanding.
- The provider-neutral OCR contract models English, Hausa, Arabic and French, including per-page text, uncertain spans, word confidence and normalized geometry. Google Cloud Vision is the initial backend adapter; OCR provider accuracy approval remains open pending a fixed, permissioned multilingual corpus and comparison with an alternative.
- The signed-in Android review flow connects the explicit OCR consent dialog to authenticated single-document upload, bounded result polling, and the OCR result screen. The UI explicitly limits each processing run to one selected document; it does not silently upload only the first file from a multi-selection.
- The authenticated OCR result reader calls `GET /v1/documents/{document_id}/result`, validates the returned document reference and bounded payload, and converts server Unicode code-point uncertainty offsets to Android UTF-16 ranges. The result screen preserves original OCR text, highlights uncertain spans without changing the source text, and supports text selection.
- The OCR result screen exposes read-aloud controls through the Android speech layer. Actual voice quality, pause/replay behavior, language coverage, TalkBack and low-vision acceptance still require physical-device testing.
- A reusable AI explanation consent dialog lets the user choose OpenAI or Gemini and an output language. After explicit consent, the Android flow calls the authenticated `POST /v1/documents/{document_id}/explain` endpoint. The explanation response includes summary, detailed explanation, suggested next steps and source-quoted key facts; provider quality and source-grounding still require real-corpus review.
- The completed OCR result screen connects to document-grounded Q&A through the authenticated `DocumentQuestionScreen`. Q&A uses the server-side document ID and user access token; OCR text remains server-side and requires separate question-processing consent. The backend Q&A feature flag remains OFF pending staging acceptance.
- The Android home/review flow includes a selectable output language for English, Hausa, Arabic and French. Mixed-language OCR and Arabic right-to-left visual reading order require separate accuracy and device acceptance tests.
- A Supabase Auth REST client supports email/password sign-in, registration, refresh-token exchange and sign-out over HTTPS with a client publishable key. Encrypted Android Keystore-backed session persistence and foreground session refresh foundations exist; background WorkManager refresh, session-expiry UX and device-level security tests remain outstanding.
- The API origin is injected through `KAVORYN_API_BASE_URL` and must be a verified HTTPS Preview address. It is deliberately not hard-coded. Until that environment is configured and validated, the Android app must not upload documents; the public business website `kavorynai.com.ng` is not an API origin.
- Secure document deletion (backend PR #54 / Android PR #55), read-aloud improvements (PR #56), key-detail verification (PR #57), explicit text sharing (PR #58), OCR benchmark tooling (PR #60), and email verification/password recovery (PR #61) are separate open Draft PRs. They are not treated as integrated into this branch until reviewed and merged in dependency order.
- Preview acceptance remains separate from code/CI status: authenticated upload → OCR worker → encrypted result retrieval → consented explanation/Q&A → owner-scoped deletion has not yet passed as a full end-to-end test.
- Supply `SUPABASE_URL` and `SUPABASE_PUBLISHABLE_KEY` through Gradle properties or environment variables. Never commit a service-role key or provider secret. Keep document upload, OCR worker, document Q&A, chat, and deletion feature flags disabled until their individual staging gates pass.

## Non-negotiable requirements

- Explicit confirmation before sending user content to external AI/OCR services.
- Delete-after-use by default; saving is opt-in.
- Keep original OCR text visible for verification.
- Signal uncertainty; never invent critical names, dates, amounts, or instructions.
- Treat uploaded content as untrusted data, not instructions.
- Support TalkBack, large text, contrast, keyboard navigation, and reduced motion.
- Never expose API keys or privileged database credentials in the Android client or source control.

A feature is not complete until it is implemented and passes the relevant security, privacy, accuracy, and accessibility tests.
