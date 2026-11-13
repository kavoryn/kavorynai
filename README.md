# Kavoryn

Kavoryn is an accessibility-first application that helps people read, understand, and listen to documents in their chosen language. The authoritative product requirements are in Kavoryn Project Plan v3.1 (12 November 2026).

## Delivery stages

- Stage A: document scan/upload, multi-page OCR, uncertainty flags, grounded explanations (Summary, Detailed, What to do), key details, language selection, text-to-speech, document-grounded Q&A, consent review, delete-after-use, and accessibility.
- Stage B: advanced voice dictation, voice input in Chat, and document preparation helper.
- Stage C: scene/object description, money/currency reading, and colour/light utilities. This stage must pass its accuracy and TalkBack gates before release.

## Current implementation status

- Android document intake, local PDF/image inspection, camera capture, image rotation and bounded local preview are in progress; device/build verification remains outstanding.
- A provider-neutral OCR contract now models English, Hausa, Arabic and French, per-page text, uncertain spans, word confidence and normalized geometry.
- OCR provider selection is intentionally open. See `docs/OCR_PROVIDER_EVALUATION.md` for the multilingual corpus and comparison gate.
- A consent-gated OCR submission coordinator now streams SHA-256, requests a short-lived consent grant, and only then streams the document to an injected API gateway. No concrete network gateway/provider is connected yet.
- The Android home/review flow includes a selectable output language for English, Hausa, Arabic and French.
- A reusable AI explanation consent dialog lets the user choose OpenAI or Gemini and an output language, and disables confirmation until explicit consent is checked.
- An authenticated Android API client now calls `POST /v1/documents/{document_id}/explain` over HTTPS with the user's bearer token, explicit provider/language consent payload, bounded response size and sanitized errors. The UI is not yet connected to this client because Android sign-in, upload/OCR completion and document-ID state are not yet integrated end-to-end.

## Non-negotiable requirements

- Explicit confirmation before sending user content to external AI/OCR services.
- Delete-after-use by default; saving is opt-in.
- Keep original OCR text visible for verification.
- Signal uncertainty; never invent critical names, dates, amounts, or instructions.
- Treat uploaded content as untrusted data, not instructions.
- Support TalkBack, large text, contrast, keyboard navigation, and reduced motion.
- Never expose API keys or privileged database credentials in the Android client or source control.

A feature is not complete until it is implemented and passes the relevant security, privacy, accuracy, and accessibility tests.
