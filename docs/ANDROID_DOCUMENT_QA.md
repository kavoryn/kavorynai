# Android document Q&A integration

The document Q&A experience is now connected to the completed Android OCR result flow:

- The OCR result screen exposes an “Ask a question about this document” action.
- The flow passes only the completed server-side document ID, current user's bearer token, and injected HTTPS API origin to `DocumentQuestionScreen`.
- The question screen does not receive OCR text. The backend retrieves the encrypted, owner-checked OCR result.
- The user must separately consent to sending the question and document text to the selected provider for each document-specific Q&A interaction. Changing the question, provider, or answer language clears the consent checkbox.
- OpenAI/Gemini provider choice and English/Hausa/Arabic/French answer language are available.
- The answer UI displays source excerpts and explicitly indicates when the document does not contain enough information.
- Questions and answers are held in Compose state only and are not persisted by this screen.

## Release and safety gates

- The Android API origin remains injected and must be a verified HTTPS staging origin; the public website domain is not assumed to be the API.
- The backend `DOCUMENT_QA_ENABLED` flag remains OFF. The connected UI will show the server's disabled response until the staging feature is deliberately enabled.
- No provider key, Supabase secret key, or service-role credential is placed in Android.
- Do not enable upload, OCR worker, or Q&A flags or deploy until authenticated staging tests, ownership isolation, consent recording, rate limits, and provider behavior have passed.
- Real-device TalkBack, large-text, contrast, reduced-motion, Hausa/Arabic RTL and low-memory acceptance tests remain outstanding.
