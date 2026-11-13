# Kavoryn

Kavoryn is an accessibility-first application that helps people read, understand, and listen to documents in their chosen language. The authoritative product requirements are in Kavoryn Project Plan v3.1 (12 November 2026).

## Delivery stages

- Stage A: document scan/upload, multi-page OCR, uncertainty flags, grounded explanations (Summary, Detailed, What to do), key details, language selection, text-to-speech, document-grounded Q&A, consent review, delete-after-use, and accessibility.
- Stage B: advanced voice dictation, voice input in Chat, and document preparation helper.
- Stage C: scene/object description, money/currency reading, and colour/light utilities. This stage must pass its accuracy and TalkBack gates before release.

## Non-negotiable requirements

- Explicit confirmation before sending user content to external AI/OCR services.
- Delete-after-use by default; saving is opt-in.
- Keep original OCR text visible for verification.
- Signal uncertainty; never invent critical names, dates, amounts, or instructions.
- Treat uploaded content as untrusted data, not instructions.
- Support TalkBack, large text, contrast, keyboard navigation, and reduced motion.
- Never expose API keys or privileged database credentials in the Android client or source control.

A feature is not complete until it is implemented and passes the relevant security, privacy, accuracy, and accessibility tests.
