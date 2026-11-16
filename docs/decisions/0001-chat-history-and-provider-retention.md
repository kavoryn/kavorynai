# Decision record: Chat history and provider retention

Status: Proposed; implementation blocked on privacy and storage design.
Date: 2026-11-16
Scope: General-purpose Kavoryn AI Chat (OpenAI Responses API and Gemini Interactions API).

## Context

Kavoryn currently persists user and assistant text in its own private chat tables, but the message endpoint sends only the latest user message to the selected provider. Therefore a conversation is stored locally but is not yet a true multi-turn model conversation.

The current provider request factories deliberately set store=false. This avoids silently enabling provider-side conversation storage. The API contract also says that chat content must not be logged and that retention/deletion must be disclosed and implemented before production.

## Verified provider API constraints

### OpenAI Responses API

The Responses API accepts input as either a string or an array of role-bearing message items. Prior user and assistant text can therefore be sent as a bounded transcript on each request while keeping provider-side response storage disabled. The existing top-level instructions field can continue to carry the stable Kavoryn system instruction.

Official reference: https://developers.openai.com/api/reference/resources/responses/methods/create

### Gemini Interactions API

The Interactions API supports multi-turn requests in two ways:

1. Stateful chaining with previous_interaction_id. This relies on provider-side stored interactions; Google documents different retention periods by tier.
2. Stateless requests with store=false, where the caller must resend the complete prior steps array and append the new user_input step. When thinking or tools are used, the complete model-generated steps (including signatures and intermediate steps) must be preserved exactly; rebuilding history from only visible assistant text is not equivalent.

Official references:
- https://ai.google.dev/gemini-api/docs/interactions-overview
- https://ai.google.dev/gemini-api/docs/interactions-breaking-changes-may-2026

## Current implementation gap

- private.chat_messages stores user/assistant text, but not Gemini's full interaction step sequence.
- Existing provider adapters accept one string, not a bounded conversation context.
- No explicit decision has been approved to let Gemini retain interaction state server-side.
- Existing chat message content is not application-layer encrypted in the current migration.
- The message endpoint currently generates a new server turn UUID and does not consume Android client_turn_id; retries can therefore create duplicate provider work.

## Options

### A. Enable provider-side state for Gemini

Persist the latest Gemini interaction ID per conversation and send it as previous_interaction_id. This is technically straightforward, but changes the data-retention boundary: Google stores interaction state. It must not be enabled until the user-facing consent notice and privacy/retention policy explicitly disclose this behavior, deletion semantics are implemented and tested, and the provider's current retention terms are reviewed.

### B. Keep Gemini stateless and persist complete provider steps locally

Keep store=false, capture the complete response step sequence, and persist it encrypted in Kavoryn-owned storage. This best preserves the intended privacy boundary, but requires a reviewed encryption/key-management design, bounded step validation, retention/deletion handling, and integration tests. The current plaintext chat schema is not sufficient for this.

### C. Reconstruct Gemini context from visible text only

Do not use this as the production solution. It can discard model-generated steps/signatures required by the Interactions API when thinking or tools are involved. It risks invalid or degraded follow-up requests and is not equivalent to preserving the provider's conversation state.

## Proposed safe direction

- Keep both providers' provider-side storage disabled until the retention decision is explicit.
- Implement OpenAI bounded role/content history using the local conversation store and store=false.
- Do not claim Gemini multi-turn support until either Option A receives explicit policy approval or Option B has an encrypted step-ledger design and tests.
- Do not store model hidden reasoning as application-visible chat text. If Gemini's required step history includes non-user-facing steps, preserve them only as opaque, access-controlled provider context if Option B is selected.
- Add atomic turn claiming keyed by authenticated user + conversation + client turn UUID before provider invocation. Replays must return/reconcile the existing turn and must never start a second provider request. A database uniqueness constraint alone is not sufficient unless the route checks the atomic claim result before calling the provider.
- Bound history by both message count and total UTF-8 bytes/tokens; include only completed turns from the same owner and conversation. Never include failed, cancelled, pending or other-user records.
- Keep chat content out of application logs and analytics. Ensure deletion removes local provider context and clarify that provider-side deletion is not guaranteed unless supported and verified.

## Release gates

- [ ] Decide and disclose Gemini provider-side retention vs encrypted local step storage.
- [ ] Define chat retention duration and account-deletion behavior.
- [ ] Review/enforce encryption at rest for chat content and any provider step ledger.
- [ ] Implement bounded history for each provider with provider-specific contract tests.
- [ ] Add atomic idempotent turn claiming before provider invocation.
- [ ] Test retries, concurrent duplicate submissions, cancellation, provider failure and partial-stream persistence.
- [ ] Verify owner isolation and deletion of all local context.
- [ ] Pass authenticated staging end-to-end tests.
- [ ] Keep CHAT_ENABLED off until all release gates pass.

No schema migration, secret, environment variable, feature flag, provider storage setting, deployment, or production resource is changed by this decision record.
