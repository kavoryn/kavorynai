# Stage A — Document API contract (draft)

Status: draft for implementation and provider testing  
Version: 0.1  
All endpoints require HTTPS. Exact auth mechanism, API base URL, upload caps and job transport remain deployment decisions.

## Shared rules

- JSON uses UTF-8.
- Every response includes a request ID.
- Never return stack traces, secrets, provider credentials, internal file paths, or private logs.
- Authenticated user identity is derived from the verified session/token, never from a request body field.
- User-specific resources are authorized server-side on every request.
- Idempotency keys are required for job creation and delete operations where retries can duplicate work.
- Rate limits and plan allowances are enforced on the server.
- Client must treat all returned OCR/model text as untrusted display data.

## Consent

Before upload or external processing, the app displays: what will be sent, why, selected output language, AI-generated nature of result, delete-after-use default, and Cancel / Continue.

A consent grant is short-lived and bound to:
- authenticated user/session,
- action (document OCR + explanation),
- payload digest or upload intent,
- selected language and optional translation flag,
- expiry and one-time nonce.

The server rejects absent, expired, reused, or mismatched consent. A cancellation before confirmation sends no document data. Session-level remembered confirmation is OFF by default, resets when app closes, and never covers saving/sharing.

## Endpoints (proposed)

### GET /v1/health
Liveness only. Must not disclose database/provider status, environment variables or secrets.

200:
{
  "status": "ok",
  "service": "kavoryn-api"
}

### POST /v1/consents
Creates a short-lived consent grant after user confirms the review screen.

Request:
{
  "action": "document.process",
  "language": "ha",
  "translate": false,
  "payload_sha256": "<sha256>",
  "confirmed": true
}

Response:
{
  "consent_id": "<opaque-id>",
  "consent_token": "<opaque-short-lived-token>",
  "expires_at": "<ISO-8601>"
}

The API must record consent metadata without recording document content. Consent is not a substitute for authorization.

### POST /v1/documents
Multipart upload. Required parts:
- file: image or PDF
- consent_token: one-time token
- idempotency_key: opaque client-generated key
- output_language: supported language code
- translate: boolean

Server checks actual MIME signature, extension consistency, size, page count, decompression limits, PDF safety, user quota, consent binding and rate limit. Store temporary uploads outside public web root with random names. Never trust filename or MIME header alone.

202:
{
  "document_id": "<opaque-id>",
  "job_id": "<opaque-id>",
  "status": "queued"
}

Errors include stable codes: unsupported_file, file_too_large, page_limit_exceeded, consent_required, consent_expired, quota_exceeded, rate_limited, processing_unavailable.

### GET /v1/jobs/{job_id}
Returns state for the authenticated owner only.

200:
{
  "job_id": "<opaque-id>",
  "status": "queued|processing|succeeded|failed|cancelled",
  "progress": {
    "stage": "upload|ocr|explain|finalize",
    "percent": 0
  },
  "retryable": false
}

No document text is included until the result endpoint. Progress values are approximate and must not be invented.

### GET /v1/documents/{document_id}/result
Returns original OCR and generated explanation as separate fields.

200:
{
  "document_id": "<opaque-id>",
  "language": "ha",
  "original_ocr": {
    "text": "...",
    "uncertain_spans": [
      {"start": 12, "end": 18, "reason": "low_confidence"}
    ]
  },
  "explanation": {
    "summary": "...",
    "detailed": "...",
    "what_to_do": ["..."],
    "key_details": [
      {"type": "date|amount|name|number|deadline", "value": "...", "source_span": [0, 12], "confidence": "good|check_it"}
    ]
  },
  "disclaimer": "AI-generated explanation. Not professional medical, legal, or financial advice."
}

All key details should include a source span/reference to original OCR where possible. Never present inferred or missing details as facts. The user must be able to inspect original OCR.

### POST /v1/documents/{document_id}/questions
Document-grounded Q&A only. The answer must cite relevant OCR snippets/spans internally or in UI. If the answer is not supported by the document, respond that the document does not provide enough information.

### POST /v1/documents/{document_id}/delete
Immediately deletes temporary file, OCR/explanation artifacts and any provider-side temporary resource where provider supports deletion. Return deletion status and a request ID. The operation must be safe to retry.

## Error shape

{
  "error": {
    "code": "stable_machine_code",
    "message": "Short user-safe explanation",
    "request_id": "<opaque-id>"
  }
}

## Data and retention

- Delete-after-use is default.
- No document/image content in analytics, usage events, audit events or ordinary application logs.
- Saving is opt-in and must have a separate explicit action and retention notice.
- Temporary data must be deleted on success, failure, timeout, cancellation and expiry.
- Deletion must be verified with automated tests and a cleanup monitor.

## Stage A acceptance checklist

- [ ] Consent absent/expired/replayed/mismatched is rejected.
- [ ] Cancel on review sends no document bytes.
- [ ] Cross-account document/job/result access is rejected.
- [ ] Upload validates actual MIME, size, page count and malformed files.
- [ ] OCR returns original text and uncertainty spans.
- [ ] Summary, Detailed and What to do are grounded in OCR.
- [ ] Dates, amounts, names, numbers and deadlines have source references.
- [ ] Document Q&A refuses unsupported answers.
- [ ] Temporary files are removed in success/failure/timeout/cancel paths.
- [ ] Logs contain no document text, images, secrets or tokens.
- [ ] TalkBack can complete Home → Review → Result without assistance.
- [ ] Hausa characters ɓ, ɗ, ƙ, ƴ and Arabic RTL pass the fixed test set.
- [ ] TTS supports pause, replay and speed adjustment.
- [ ] Offline/connection loss and retry behavior is tested.
