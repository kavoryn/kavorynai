# Stage B Implementation Plan

**Status:** Planning and implementation preparation only  
**Target branch:** `integration/preview`  
**Release state:** Not enabled; no Production release is authorised by this plan

Stage B can be developed in parallel with the remaining Stage A acceptance work. Stage A remains **implementation mostly complete, acceptance pending** until its Preview, OCR-quality, accessibility, account-recovery, privacy, and security evidence is complete.

## Scope and delivery order

### B0 — Integration baseline and safety

- Build new work from `integration/preview`; do not merge the older chained UI branches as-is.
- Keep all Stage B changes in Draft pull requests targeting `integration/preview`.
- Keep `CHAT_ENABLED` and other release flags disabled until their individual release gates pass.
- Do not apply Supabase migrations, change project settings or environment variables, or deploy to Production without separate explicit approval.
- Treat CI and local container smoke tests as code-level evidence only; they do not prove a live Preview or authenticated end-to-end behaviour.

### B1 — Authenticated Chat workspace

The current backend on `integration/preview` contains authenticated chat routes for provider consent, conversation creation/list/read/delete, and server-sent-event message streaming. The routes are guarded by `CHAT_ENABLED` and fail closed when the required auth or private storage configuration is absent.

Implementation work:

1. Rebuild the Android Chat workspace against the current `MainActivity`, session lifecycle, and API configuration.
2. Support explicit OpenAI/Gemini selection and separate provider consent before sending document or chat content to the selected provider.
3. Support English, Hausa, Arabic, and French conversation preferences.
4. Add bounded SSE parsing, visible partial-response state, retry/error handling, and cancellation that closes the client stream.
5. Add automated tests for request validation, provider selection, consent gating, stream completion/error/cancellation, and conversation history.
6. Keep the UI unavailable while the server release flag is off; do not add a client-side bypass.

**Existing work to review, not merge blindly:**

- PR #35, “Android AI Chat workspace and consent-first integration”, is an older Draft based on `feature/premium-android-home-ui`, not `integration/preview`. Its previous Android build passed on head `78099462bd17a0d813d3dd620488bd4f0b178bfd`. Reuse concepts and code only after reconciling against the current Stage A UI and adding tests.
- The private chat schema foundation PR #26 is merged into `integration/preview`, but that does not mean its migration has been applied to the connected Supabase project.
- Chat remains gated off. A Draft PR or passing CI is not permission to apply its migration or enable the feature.

### B2 — Voice dictation in Chat

The goal is an editable speech-to-text input for the Chat composer, not an automatic-send microphone.

Implementation work:

1. Create a reusable voice-dictation component/service and integrate it into the Chat composer.
2. Ask for microphone permission only after the user taps the microphone.
3. Show a clear pre-use notice and obtain explicit consent before launching a device speech-recognition service. Explain that audio is handled by that service and may be processed by its provider; do not claim that recognition is on-device unless it is verified.
4. Keep recognised text editable. Never send it automatically; the user must review and press Send.
5. Do not persist raw microphone audio. Preserve typed input as a full fallback if permission or recognition is unavailable.
6. Detect language support at runtime. Do not promise Hausa, Arabic, English, or French recognition on a device until the selected recognition service reports it is available.
7. Test permission granted/denied, service unavailable, empty result, cancellation, language selection, text editing, and TalkBack labels on Android.

**Existing work to review, not merge blindly:**

- PR #37, “Listen & Voice workspace”, is an older Draft based on `feature/android-document-center`, not `integration/preview`. Its previous Android build passed on head `c44cbe10998a14529922c662bce54abb5e217d6c`.
- That PR adds a separate Listen & Voice workspace and uses the Android system recognition service. It does not yet connect dictation to the Chat composer.
- Reconcile the old microphone permission and consent UI with the current app manifest, current navigation, and privacy wording before reuse.

### B3 — Document Preparation Helper

Provide guided preparation based on a document the signed-in user has already uploaded and processed.

Expected behaviour:

1. Require a completed, owner-verified document record and a separate, explicit AI-processing consent for the selected provider and purpose.
2. Read OCR text server-side from the protected document result. Do not accept client-supplied OCR text as authoritative and do not expose storage or provider secrets to Android.
3. Let the user choose a preparation goal (for example, understand requirements, make a checklist, draft a letter, organise an application, or prepare questions for an office).
4. Return structured output: information found, missing information, uncertain or unreadable fields, suggested next steps, and a user-editable draft where relevant.
5. Ground factual statements in source excerpts or OCR spans. Clearly label assumptions and missing information; never invent names, dates, amounts, IDs, eligibility, or official decisions.
6. Keep the result as a draft for user review. Do not submit forms, contact organisations, pay fees, or make legal/official decisions on the user's behalf.

Before implementation, define a versioned API contract, consent purpose, request/response limits, rate limits, and tests for owner isolation and source-grounding. Do not silently reuse general Chat consent for a different document-preparation purpose.

## Acceptance evidence for Stage B

Stage B is ready for a controlled Preview evaluation only when all applicable items below have evidence:

- Android unit/build checks pass on the exact Draft PR head.
- API tests prove authentication, owner isolation, consent gating, bounded requests/responses, and safe failure behaviour.
- Chat SSE tests cover normal completion, malformed events, provider errors, interrupted streams, cancellation, and response-size limits.
- Voice dictation tests and physical-device checks cover microphone permission, privacy notice, recognition availability, transcript editing, typed fallback, Hausa/Arabic language behaviour where supported, and TalkBack.
- Document Preparation tests prove server-side OCR retrieval, source excerpts, uncertainty handling, missing-field handling, and no cross-user document access.
- A legitimate Vercel Preview is created for the exact current commit, and runtime endpoints are verified without bypassing Vercel SSO.
- Real provider E2E tests use approved staging configuration and explicit test consent. Synthetic CI tests must not be reported as real provider validation.
- Any required Supabase migration, environment configuration, or feature-flag change receives separate explicit approval before execution.

## Current boundaries

- Stage A acceptance is still pending; Stage B implementation does not change that status.
- No Supabase migration has been applied remotely by this plan.
- No feature flag has been enabled.
- No Vercel SSO, project setting, environment variable, Production deployment, branch merge, or APK release is changed by this plan.
- The separate `kavorynai.com.ng` business website and its DNS are out of scope.
