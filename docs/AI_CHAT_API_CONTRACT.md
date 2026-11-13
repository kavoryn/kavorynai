# Kavoryn AI Chat API Contract

Status: Phase A design only; not implemented or enabled.
Version: 0.1

## Product boundary

Define an authenticated general-purpose chat that explicitly selects OpenAI or Gemini. Chat is separate from document Q&A; normal chat cannot access a user's documents. Provider credentials and Supabase elevated credentials remain server-only.

## Shared security rules

- Require HTTPS outside local development.
- Authenticate with the existing Supabase Auth bearer-token verification. Derive user ID from the verified session; never trust a client-supplied user ID.
- Every conversation and message read/write is owner-scoped and protected by database row-level security.
- Provider selection is exactly `openai` or `gemini`; never silently switch providers.
- Never send provider API keys or Supabase elevated credentials to Android, return them, commit them, or log them.
- Treat user messages, files, retrieved pages and model output as untrusted data.
- Bound request bytes, message characters, context/output tokens, execution time, concurrent streams and per-user rate.
- Use opaque IDs, parameterized database operations, safe error codes and `Cache-Control: no-store`.
- Do not log prompts, completions, access tokens or private memory. Log only minimal operational metadata.
- Cancellation must stop upstream generation where supported and must not mark partial output as completed.

## Provider consent

Before the first message to a provider, show a notice identifying the selected provider, that message content is sent to it, and the applicable privacy/retention policy. Require explicit confirmation. Persist only consent metadata (user, provider, purpose `chat.message`, policy version, timestamp), never message text. Changing provider requires fresh confirmation. This consent does not authorize document processing, memory storage, web research or tool actions.

## API contract

All routes are under `/v1`. JSON is UTF-8. JSON responses include `request_id`.

### POST /v1/chat/conversations

Create an empty conversation; creation alone sends no content to an AI provider.

Request:
```json
{"title":"Optional title","provider":"openai","language":"ha"}
```

Provider is required (`openai|gemini`); language is required (`en|ha|ar|fr`); title is optional plain text up to 120 Unicode characters.

Response `201`:
```json
{"conversation":{"id":"opaque-uuid","title":"Optional title","provider":"openai","language":"ha","created_at":"ISO-8601","updated_at":"ISO-8601"},"request_id":"opaque-id"}
```

### GET /v1/chat/conversations

Return only the authenticated user's conversations, newest activity first, using bounded pagination and an opaque cursor. Do not return message bodies in the list.

### GET /v1/chat/conversations/{conversation_id}

Return one owner-verified conversation and a bounded, stable page of its messages. Return the same `conversation_not_found` response for missing and other-user IDs to prevent enumeration.

### POST /v1/chat/conversations/{conversation_id}/messages

Submit one user turn and stream the assistant response as Server-Sent Events (SSE).

Request:
```json
{"message":"Explain this in simple Hausa.","provider":"openai","language":"ha","confirmed_provider_consent":true,"client_turn_id":"random-id"}
```

- Message length: 1–12,000 Unicode characters after normalization.
- Provider must match the conversation provider. Provider changes require a separate owner-authorized update and fresh consent.
- Require explicit provider consent unless a valid, unrevoked consent for this provider and purpose is already recorded under the approved policy.
- `client_turn_id` is an idempotency key scoped to user and conversation; replay must not create duplicate provider work.
- Persist user turn safely before generation; assistant state must be marked completed, failed or cancelled accurately.
- Load only bounded recent history belonging to this owner. Durable memory retrieval is Phase B, not part of this API.
- Complete auth, ownership, validation, consent, idempotency, rate and concurrency checks before provider invocation.

SSE headers:
- `Content-Type: text/event-stream; charset=utf-8`
- `Cache-Control: no-store, no-transform`
- `X-Accel-Buffering: no`

Event examples:
```text
event: message.started
data: {"turn_id":"opaque-id","request_id":"opaque-id"}

event: message.delta
data: {"turn_id":"opaque-id","text":"Sannu"}

event: message.completed
data: {"turn_id":"opaque-id","message_id":"opaque-id","usage":{"input_tokens":0,"output_tokens":0}}

```

Other events: `message.error` (safe code/message), `message.cancelled`, and optional `heartbeat`. Exactly one terminal event (completed/error/cancelled) must end a stream. Client disconnect should cancel upstream generation where feasible.

### POST /v1/chat/conversations/{conversation_id}/turns/{turn_id}/cancel

Cancel a running turn owned by the authenticated user. Idempotent; cannot affect another user's turn.

### DELETE /v1/chat/conversations/{conversation_id}

Delete the owner's conversation and messages under the disclosed retention policy. Require explicit UI confirmation. Safe to retry. Do not claim provider-side deletion unless supported and verified.

## Proposed private persistence model

Names are proposals and must be reconciled with existing migrations before implementation.

### private.chat_conversations
- `id uuid primary key`
- `user_id uuid not null`
- `title text null` (max 120 chars)
- `provider text not null check in ('openai','gemini')`
- `language text not null check in ('en','ha','ar','fr')`
- `created_at, updated_at timestamptz not null`
- `deleted_at timestamptz null`

### private.chat_messages
- `id uuid primary key`
- `conversation_id uuid not null references private.chat_conversations(id)`
- `user_id uuid not null`
- `turn_id uuid not null`
- `role text not null check in ('user','assistant')`
- `content text not null` (bounded by API; evaluate application-layer encryption before launch)
- `status text not null check in ('pending','streaming','completed','failed','cancelled')`
- `provider, model text null`
- `input_tokens, output_tokens integer null`
- `created_at timestamptz not null`, `completed_at timestamptz null`

### private.chat_provider_consents
- `id uuid primary key`
- `user_id uuid not null`
- `provider text not null check in ('openai','gemini')`
- `purpose text not null check in ('chat.message')`
- `policy_version text not null`
- `created_at timestamptz not null`, `revoked_at timestamptz null`

Rate limiting must use a transaction-safe database RPC or equivalent; never an in-process PHP counter.

## Retention and memory separation

- Chat history is private and user-scoped. Decide and disclose retention duration before live persistence.
- Implement conversation deletion and account-deletion integration before production.
- Chat history is not durable memory. Phase B memory requires separate opt-in, review/correction/deletion controls, retention limits and strict per-user isolation.
- Never infer a durable memory from a model guess or third-party content.

## Stable error codes

`unauthorized`, `conversation_not_found`, `provider_consent_required`, `provider_not_configured`, `provider_unavailable`, `rate_limited`, `concurrency_limit`, `message_too_large`, `invalid_provider`, `turn_in_progress`, `turn_cancelled`, `chat_storage_unavailable`.

Never return raw provider responses, exception messages, SQL errors or stack traces.

## Phase A implementation order

1. Review current schema, RLS, migrations and PHP routing conventions.
2. Add private tables, RLS and atomic rate-limit storage; do not apply migrations remotely from CI.
3. Implement repository interfaces and persistence adapters.
4. Implement provider-neutral chat contract and explicit OpenAI/Gemini adapters with bounded context/output.
5. Implement authenticated endpoints, SSE and cancellation.
6. Test auth, cross-user isolation, consent, idempotency, limits, cancellation, provider failures and stream terminal states.
7. Add accessible Android chat UI, provider/language selector, consent, streaming, retry and cancel controls.
8. Configure verified HTTPS staging API origin and pass end-to-end tests before any enablement.

## Release gates

- [ ] RLS prevents cross-user reads and writes.
- [ ] Provider credentials are server-only.
- [ ] OpenAI and Gemini are explicitly selectable; no silent fallback.
- [ ] Provider consent is recorded before external requests.
- [ ] Streaming, cancellation, disconnect, timeout and partial failures are tested.
- [ ] Atomic rate and concurrency limits are tested.
- [ ] Chat content is excluded from logs and analytics.
- [ ] Retention and deletion policy is approved and implemented.
- [ ] TalkBack, large text, contrast, RTL and supported languages are verified.
- [ ] Staging end-to-end tests pass on selected PHP host.
- [ ] Production enablement/deployment receives separate authorization.

No migration is applied and no feature flag is enabled by this design document.
