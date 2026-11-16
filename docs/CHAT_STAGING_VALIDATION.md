# Chat staging validation runbook

This runbook is for a disposable, authenticated staging environment only. It is not permission to deploy, apply migrations to the live Kavoryn Supabase project, enable production flags, or change provider retention settings.

## Entry gates

Before running any provider-backed test:

- Use a dedicated Supabase staging project or isolated Supabase development branch with disposable test accounts and no real user documents.
- Confirm the backend Preview deployment points to the exact reviewed Git commit and uses staging-only environment variables. The new integrated candidate is PR #73, branch `integration/chat-stageb-secret-key-preview` (current code head before this runbook update: `415c7b6b4ac6f4a19ab18a4da66cbacda3ebdfae`); it has no branch-scoped Vercel environment variables or Preview deployment yet. The only known READY Preview is `https://kavoryn-qab6l4d5b-kavoryn.vercel.app`, commit `253b6be0aa99e428f070bd6dd0547e336913d0db`, branch `feat/atomic-chat-turn-idempotency`; it is an older code build and authenticated PHP runtime behavior is not verified.
- Keep `CHAT_ENABLED` disabled in Production and staging until server-side credentials are configured and the security gates are reviewed. Do not enable it merely because the deployment is READY.
- For PR #73, add branch-scoped Preview values for `SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY`, `SUPABASE_SECRET_KEY`, `OPENAI_API_KEY`, and `GEMINI_API_KEY`; use values from the dedicated staging project and provider accounts. The candidate uses the new Supabase secret API key in the `apikey` header only. It does not require `SUPABASE_SERVICE_ROLE_KEY`; never send an `sb_secret` key as a Bearer token. Metadata-only inspection found no environment entries scoped to this new branch, and the known project-wide private-key placeholders are empty. Never place provider keys in Android resources, logs, or client responses.
- Confirm provider consent is recorded explicitly for each test account/provider.
- Confirm provider-side storage remains disabled (`store: false`) for OpenAI. Do not enable Gemini stateful retention for this runbook.
- Record deployment URL, Git SHA, migration versions, test account IDs (non-production only), and UTC test time in the staging report. Do not record access tokens or secret values.

## Test sequence

### 1. Health, auth, and feature gating

1. Verify `GET /health` returns healthy.
2. Verify unauthenticated `POST /v1/chat/conversations` and message requests return 401.
3. Verify chat routes remain unavailable while `CHAT_ENABLED=false`.
4. Verify a valid staging user can create an empty conversation without provider consent.
5. Verify a user cannot read, message, or delete another user's conversation.

### 2. Consent boundary

1. Create an OpenAI conversation before consent; this must not call a provider.
2. Attempt to send a message without OpenAI consent; expect `chat_provider_consent_required` and verify no provider request occurred.
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

1. Send a message with a new UUID `client_turn_id`; verify one user row and one completed assistant row share the server `turn_id`.
2. Replay the exact same request with the same `client_turn_id`; verify the saved answer is streamed with `replayed=true` and no second provider call occurs.
3. Replay the same `client_turn_id` with different content; expect `chat_turn_id_conflict`.
4. Submit two different client turn IDs concurrently to one conversation; verify only one provider generation starts and the other receives `chat_turn_in_progress`.
5. Verify a completed turn can be replayed when provider credentials are temporarily unavailable, without a provider call.
6. Verify failed/cancelled turns are terminal and a deliberate new attempt requires a new client turn ID.
7. Verify rate limiting applies to new claimed turns and a completed replay does not consume another provider quota.

### 5. Gemini limitation and safe behavior

1. Send one Gemini turn with explicit Gemini consent; verify the first response succeeds.
2. Replay that exact first turn with the same client ID; verify the saved answer is returned without another provider call.
3. Send a second distinct Gemini turn in the same conversation; expect `chat_multi_turn_not_supported`.
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
- OpenAI multi-turn works with bounded local context and `store: false`.
- Gemini remains explicitly single-turn unless a separately approved safe history implementation is complete.
- The staging deployment, database, and environment are confirmed isolated from Production.

## Current staging status (2026-11-16)
- PR #71 follow-up commit `71a99af8e70b8e7373010c8e7df1ffa26e77901f` fixes a backend edge case: if assistant-response persistence fails after the provider stream completes, the handler now attempts to mark the turn `failed` rather than leaving it `processing`. A regression test was added. Both PHP API checks and Chat PostgreSQL security integration completed successfully on this exact commit.

- A direct rollback-only transaction test was run against staging for OpenAI turn claim, duplicate in-progress retry, conflicting payload rejection, atomic completion, completed-answer replay, and the one-user/one-assistant-row invariant. A second rollback-only test verified that cancelled and failed turns remain terminal, while a deliberate new client turn ID can be claimed. Gemini's second distinct turn was also verified to fail closed. All assertions passed; after both rollbacks, verification found zero temporary users, conversations, messages, consents, or turn-request rows.
- A separate Supabase staging project `kavoryn-chat-staging` (project ref `czaholjpagapvglswssd`, region `eu-west-1`) is active. Four chat migrations are applied there: private chat storage, private chat operations, bounded context RPC, and atomic chat-turn idempotency. The live Supabase project has no chat migrations applied; this is intentional. Do not apply these migrations to Production for staging.
- The three turn-idempotency RPCs (`claim_chat_turn`, `complete_chat_turn`, and `fail_chat_turn`) are SECURITY DEFINER with an empty `search_path`; execution is denied to `anon` and `authenticated` and granted to `service_role` only. All five private chat tables have RLS enabled, and direct SELECT is denied to `anon`, `authenticated`, and `service_role`.
- Supabase security advisor reports five `rls_enabled_no_policy` INFO findings on these private tables. This is consistent with the server-only RPC access design and should remain subject to review; do not add broad policies merely to silence the notices. Health advisor currently reports no findings.
- Supabase performance advisor reports two unindexed foreign keys (`chat_messages.user_id` and `chat_turn_requests.conversation_id,user_id`) and three unused indexes. These are INFO-level review items; assess representative query plans and add appropriate indexes in a reviewed migration before a production release if justified.
- PR #73 was created as a Draft integration candidate combining PR #54's secret-key-only PHP backend and secure deletion foundation, PRs #65/#70 Android Chat and client-turn idempotency, and PRs #67–#71 consent, bounded OpenAI context and atomic idempotency. Exact head `415c7b6b4ac6f4a19ab18a4da66cbacda3ebdfae` triggered PHP API, Chat PostgreSQL security integration, and Android unit-test workflows; all three were still in progress at the time of this update. Do not treat earlier green workflows on separate source PRs as a substitute for these exact-head integration checks.
- The existing READY Vercel Preview is an earlier build and does not contain PR #73. The PR #73 branch currently has no branch-scoped Preview environment variables (confirmed by metadata-only listing), so no authenticated Preview test can start until its variables are securely configured and a fresh deployment can be created.
- The combined PR #73 follows Supabase's new API-key model: `SUPABASE_SECRET_KEY` (format `sb_secret_...`) is server-only and used in the `apikey` header; the key maps to the service role. It is not a JWT and must not be placed in `Authorization: Bearer`. Do not add the legacy `SUPABASE_SERVICE_ROLE_KEY` to this branch. The branch-specific environment inventory for PR #73 is empty; add the staging URL, publishable key, secret key, and provider keys only through Vercel's secure Preview settings. Do not put credentials in chat, GitHub, Android, or logs.
- A new Vercel deployment attempt was rejected by the Vercel API because the team reached the plan's deployment API limit for the rolling 24-hour period. The existing READY deployment remains available; retry only when Vercel permits it.
- The runtime error-log query returned no matching records in its successful 30-minute window; this is not proof that the routes were exercised or that the service is healthy.
- The document deletion migration `20261115002000_add_owner_scoped_document_deletion.sql` is present in PR #73 for CI/review only; it is not applied to the current staging database. Reconcile migration history and explicitly review it before any staging apply. Do not apply it to live Supabase.
- `CHAT_ENABLED` and document/OCR feature flags remain off. Authenticated staging end-to-end testing is pending until branch-scoped secrets are verified, a refreshed Preview deployment is available, and the route, auth, consent, and provider tests above are executed.
- PR #71 and this runbook PR remain Draft/open/unmerged. CI success is not a substitute for the staging runbook.
