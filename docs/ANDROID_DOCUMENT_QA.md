# Android document Q&A interface

The Android Q&A building blocks are in `com.kavoryn.app.qa`:

- `DocumentQuestionApi` calls the authenticated `POST /v1/documents/{document_id}/ask` endpoint with the user bearer token, provider, output language, question and explicit confirmation.
- The API root is injected by the parent and must be HTTPS. The website domain is not assumed to be the API host.
- `DocumentQuestionScreen` provides a document-specific consent notice, OpenAI/Gemini selection, English/Hausa/Arabic/French output, bounded question input, answer and exact source-excerpt display.
- No OCR text is accepted from the Android client; the backend reads the encrypted, owner-checked result.
- Questions and answers are held only in Compose state and are not persisted by this screen.

Integration remains gated: the current document intake flow does not yet produce a completed server document ID, and the staging API origin has not been configured. Do not connect this screen to a placeholder host or enable `DOCUMENT_QA_ENABLED` until upload/OCR, session and staging integration are verified. Keep all upload/OCR/Q&A feature flags off.
