# Stage A: Text-to-Speech and document-grounded Q&A

## Scope

This work advances Stage A while the separate Android Keystore emulator checks for PRs #3 and #4 continue. It does not declare Gate A passed and does not start Stage B.

Deliver two Stage A capabilities:
1. Read the document's original OCR text and the grounded explanation aloud.
2. Let the user ask a question about the currently open document and receive an answer grounded only in that document.

## Text-to-Speech requirements

- Prefer Android's platform TextToSpeech engine as the first local implementation so reading does not send document text to a cloud service.
- Provide play, pause/resume, stop, replay, and adjustable speech rate.
- Keep the spoken text synchronized with visible text where practical; never hide the original OCR text.
- Expose meaningful TalkBack labels and announce playback state changes.
- Handle missing language voices and unsupported Hausa/Arabic voices honestly; show a clear fallback or unavailable state rather than claiming support.
- Test English, Hausa special characters (ɓ, ɗ, ƙ, ƴ), Arabic RTL text, French, punctuation, numbers, and mixed-language content.
- Do not persist audio or document text for speech.

## Document-grounded Q&A requirements

- Scope every question to one authenticated, owner-checked document ID.
- Read only the completed encrypted OCR result; never accept document text supplied by the client as authoritative context.
- Require explicit, document-specific AI-processing consent and record the selected provider before sending OCR text externally.
- Reuse the existing provider-neutral explanation abstraction where appropriate, but define a distinct question-answer contract and endpoint.
- Treat OCR content and quoted instructions inside the document as untrusted data, never as system instructions.
- If the answer is not supported by the OCR text, say that the document does not provide enough information.
- Return short source excerpts that must exactly match the OCR text; do not fabricate page numbers or quotations.
- Do not store questions, answers, or document content in application logs. Apply bounded request/response sizes and rate limits.
- Keep OpenAI/Gemini provider selection explicit; no silent fallback.

## Q&A implementation status

- [x] Define a provider-neutral `DocumentQuestionAnswerer` contract.
- [x] Add fail-closed validation for bounded questions, bounded answers, and exact source excerpts.
- [x] Add tests for valid evidence, fabricated excerpts, missing evidence, contradictory insufficient-information responses, and oversized questions.
- [x] Implement OpenAI and Gemini question-answer adapters with document text treated as untrusted data and strict exact-excerpt validation.
- [x] Add authenticated owner-checked `/v1/documents/{document_id}/ask` endpoint and distinct Q&A consent audit before external processing.
- [x] Add atomic per-user rate limiting (10 requests per 60 seconds) and provider mock tests.
- [ ] Add Android Q&A screen/client and consent UX integration.
- [ ] Apply Supabase migrations and validate the endpoint in staging; keep DOCUMENT_QA_ENABLED=false until these gates pass.

## Proposed API contract (design only)

```http
POST /v1/documents/{document_id}/ask
Authorization: Bearer <user access token>
Content-Type: application/json
```

```json
{
  "question": "What is the payment deadline?",
  "confirmed": true,
  "provider": "gemini",
  "language": "ha"
}
```

The server must authenticate the user, verify document ownership and completed OCR status, persist the document-specific AI consent audit before external processing, and then call only the explicitly selected provider. The response should include the answer, exact supporting excerpts, provider, language, and request ID. This contract is not implemented or enabled by this document.

## Test gates

- Unit tests for TTS lifecycle, cancellation, language availability, and state restoration.
- Instrumented tests for TalkBack labels and playback controls.
- Fixed multilingual question/answer corpus with expected supporting excerpts.
- Negative tests for cross-user document IDs, missing consent, unsupported claims, prompt injection inside OCR text, oversized questions, rate limiting, and provider failures.
- Verify that no external call occurs before explicit consent.
- Verify that no question, answer, OCR text, or audio is written to logs or persistent storage.

## Out of scope

- Cloud TTS until voice quality, privacy, latency, and cost are evaluated.
- Stage B dictation and voice chat.
- Enabling upload/OCR flags, staging integration, or production deployment.
- Any change to the separate Kavorynai website/domain.

## Exit criteria

The features are not complete until implementation, automated tests, real-device TalkBack testing, and multilingual quality evaluation pass. Gate A remains closed until all Stage A requirements and its fixed-set accuracy/privacy/accessibility tests pass.
