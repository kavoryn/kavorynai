# Chat staging validation runbook

This runbook is for a disposable, authenticated staging environment only. It is not permission to deploy, apply migrations to the live Kavoryn Supabase project, enable production flags, or change provider retention settings.

## Entry gates

Before running any provider-backed test:

- Use a dedicated Supabase staging project or isolated Supabase development branch with disposable test accounts and no real user documents.
- Confirm the backend Preview deployment points to the exact reviewed Git commit and uses staging-only environment variables.
- Keep \`CHAT_ENABLED\` disabled in Production. Enable it only in the isolated staging environment after the migration has been reviewed and applied there.
- Confirm server-side OpenAI/Gemini credentials are configured only in the backend environment; never place provider keys in Android resources, logs, or client responses.
- Confirm provider consent is recorded explicitly for each test account/provider.
- Confirm provider-side storage remains disabled (\`store: false\`) for OpenAI. Do not enable Gemini stateful retention for this runbook.
- Record deployment URL, Git SHA, migration versions, test account IDs (non-production only), and UTC test time in the staging report. Do not record access tokens or secret values.

## Test sequence

### 1. Health, auth, and feature gating

1. Verify \`GET /health\` returns healthy.
2. Verify unauthenticated \`POST /v1/chat/conversations\` and message requests return 401.
3. Verify chat routes remain unavailable while \`CHAT_ENABLED=false\`.
4. Verify a valid staging user can create an empty conversation without provider consent.
5. Verify a user cannot read, message, or delete another user's conversation.

### 2. Consent boundary

1. Create an OpenAI conversation before consent; this must not call a provider.
2. Attempt to send a message without OpenAI consent; expect \`chat_provider_consent_required\` and verify no provider request occurred.
3. Grant explicit OpenAI consent and send a test message.
4. Revoke OpenAI consent and verify the next new message is blocked.
5. Repeat the consent flow for Gemini separately.

### 3. OpenAI multi-turn and bounded context

1. Send a first user message and verify streamed deltas and one completed assistant message are persisted.
2. Send a follow-up referring to a unique fact in the first turn; verify the assistant can use that prior conversation context.
3. Inspect server-side request telemetry (without logging message bodies or secrets) to verify only bounded recent completed role/content messages are sent.
4. Verify failed, cancelled, pending, and other-user messages are excluded from context.
5. Verify the context is bounded to the configured message and character limits, including the current user message budget.
6. Verify OpenAI provider-side storage remains disabled.

### 4. Idempotency and concurrency

1. Send a message with a new UUID \`client_turn_id\`; verify one user row and one completed assistant row share the server \`turn_id\`.
2. Replay the exact same request with the same \`client_turn_id\`; verify the saved answer is streamed with \`replayed=true\` and no second provider call occurs.
3. Replay the same \`client_turn_id\` with different content; expect \`chat_turn_id_conflict\`.
4. Submit two different client turn IDs concurrently to one conversation; verify only one provider generation starts and the other receives \`chat_turn_in_progress\`.
5. Verify a completed turn can be replayed when provider credentials are temporarily unavailable, without a provider call.
6. Verify failed/cancelled turns are terminal and a deliberate new attempt requires a new client turn ID.
7. Verify rate limiting applies to new claimed turns and a completed replay does not consume another provider quota.

### 5. Gemini limitation and safe behavior

1. Send one Gemini turn with explicit Gemini consent; verify the first response succeeds.
2. Replay that exact first turn with the same client ID; verify the saved answer is returned without another provider call.
3. Send a second distinct Gemini turn in the same conversation; expect \`chat_multi_turn_not_supported\`.
4. Verify no prior Gemini interaction ID or provider-side stateful storage is used.
5. Do not mark Gemini multi-turn as supported until an encrypted, bounded local Interactions step ledger or a separately approved retention design is implemented and tested.

### 6. Failure, cancellation, and deletion

1. Simulate a provider timeout and verify the turn becomes terminal without duplicate provider work on retry.
2. Cancel a stream and verify the terminal state is recorded.
3. Simulate a storage failure after provider completion; verify the client receives a safe error and no false success is recorded.
4. Delete a staging conversation and verify its messages and idempotency records are removed according to the reviewed cascade/retention policy.
5. Verify logs contain request IDs and safe error codes only, not message bodies, access tokens, or provider credentials.

## Exit criteria

- All relevant PHP, Android, and disposable PostgreSQL CI checks pass on the exact commit under test.
- Every test above has a recorded pass/fail result; failures have linked issues and owners.
- No cross-user data access, duplicate provider invocation, unconsented provider call, or unexpected provider-side retention is observed.
- OpenAI multi-turn works with bounded local context and \`store: false\`.
- Gemini remains explicitly single-turn unless a separately approved safe history implementation is complete.
- The staging deployment, database, and environment are confirmed isolated from Production.

## Current known blockers (2026-11-16)

- The live Supabase project has no chat migrations applied; this is intentional. Do not apply the chat migrations to it for staging.
- No Supabase development branches are currently present on the connected project.
- No Vercel deployment exists for the current idempotency PR head. A generic READY deployment for an older SHA is not evidence that the current backend container is running.
- Authenticated staging end-to-end testing therefore remains pending. CI success is not a substitute for this runbook.
