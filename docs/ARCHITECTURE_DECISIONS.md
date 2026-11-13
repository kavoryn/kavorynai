# Architecture decisions — Stage A

Status: **proposed, not production-approved**  
Date: 2026-11-13  
Authority: Kavoryn Project Plan v3.1

## Confirmed product constraints

- Android native client is the first client; Kotlin + Jetpack Compose is the plan's stated direction.
- PHP API is the proposed backend and the only door to the database and external AI/OCR/TTS providers.
- Database scope is accounts, settings, usage, and subscription status; document/image content is not retained by default.
- Stage A (Documents) is first. Stage B and C cannot delay its release gate.
- External processing requires an explicit review and confirmation.
- Original OCR text remains available for verification; uncertain OCR is marked.
- Delete-after-use is the default. Saving is an explicit opt-in.
- Accessibility, privacy, accuracy, and security are release gates.

## Proposed request flow

1. Android captures or selects a supported image/PDF.
2. Client validates basic size/type and compresses images locally.
3. Client shows a review screen describing data, purpose, language, provider processing, and deletion default.
4. User explicitly confirms; client obtains a short-lived, server-verifiable consent grant bound to user + action + payload digest + expiry.
5. Client uploads over HTTPS to the PHP API using an authenticated request.
6. PHP API validates identity, consent, MIME signature, size, page count, rate limit, and plan allowance.
7. API stores a temporary file outside the public web root, calls the selected OCR/AI provider, and tracks a job.
8. API returns original OCR, uncertain spans, extracted key details, explanations and confidence annotations in a stable response schema.
9. Android presents original and simplified content separately, offers Listen / Ask / Delete, and exposes uncertainty without colour-only signalling.
10. API deletes temporary files and provider artifacts in success, failure, timeout, cancellation, and expiry paths. Retention is tested, not assumed.

## Boundaries

- Android never receives database service-role credentials or AI provider secrets.
- Client-provided user IDs, plan names, usage counters, and payment status are never trusted.
- OCR text, PDFs, images, and model output are untrusted input. They cannot change system policy or trigger arbitrary tools.
- The API must not log private document text, image data, passwords, tokens, or raw consent payloads.
- Provider adapters must be replaceable; no provider is selected until comparative Hausa/Arabic tests.
- Admins see operational metrics and support metadata, not users' private documents.

## Decisions still requiring proof

| Question | Proof required | Status |
|---|---|---|
| PHP hosting suitability | Background jobs, request limits, temporary storage, env vars, SSL, queue/worker and streaming test | Open |
| Database choice | Compare plan requirements, data residency, RLS and PHP access pattern; existing Supabase project is evaluation only | Open |
| OCR provider | Fixed multilingual set; Hausa ɓ ɗ ƙ ƴ, Arabic RTL, forms/tables, multi-page PDFs; compare accuracy and cost | Open |
| AI explanation provider | Grounding, hallucination rate, Hausa/Arabic quality, latency, refusal/safety and cost | Open |
| TTS provider | Pronunciation and naturalness for English/Hausa/Arabic/French; speed and latency | Open |
| File limits | Test low-end Android and limited data; choose max size/pages from evidence | Open |
| Consent remember-in-session | Implement only exact plan behavior: OFF by default, resets on app close, never applies to save/share | Open |

## Existing infrastructure caution

A Supabase project was provisioned earlier and includes account/settings/usage/subscription/consent/audit tables. This does **not** constitute approval to use it as the final database. Reconcile its schema and policies with this document before connecting the app.

An existing Vercel project/domain is used for other work. Do not change its repository link, production domain, protection settings, or production deployment while building Kavoryn. Vercel is not presumed to host the PHP API.

## ADR acceptance

This proposal becomes an approved architecture only after:
- PHP hosting proof passes or an alternative is explicitly approved.
- Database and provider choices are recorded.
- Threat model and privacy review are complete.
- Stage A API contract and test fixtures are reviewed.
