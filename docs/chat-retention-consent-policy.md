# Kavoryn chat retention and deletion consent

## Product decision

- A conversation becomes eligible for a retention prompt after **90 days without activity**.
- Inactivity is measured from `chat_conversations.updated_at`, not from creation time.
- Reaching 90 days **never deletes data automatically**.
- The next time the signed-in owner opens Kavoryn chat, show a clear prompt asking whether they want to permanently delete that conversation and its messages.
- The owner may choose **Keep conversation** or **Permanently delete**.
- If the owner keeps it, or does not answer, retain the conversation. Do not silently delete it or treat dismissal as consent.
- A later deletion request must use a separate explicit confirmation step.

## Required server behavior

1. Derive the owner from the verified Supabase bearer session. Never accept `user_id` as authority from the request body.
2. List only that owner's conversations that are at least 90 days inactive and have not already received a retention decision for the current inactivity period.
3. Bind every decision to the authenticated owner and exact conversation ID.
4. Require an explicit affirmative value for permanent deletion. Missing, false, malformed, expired, or mismatched confirmation must not delete anything.
5. On a confirmed delete, perform one transaction that:
   - verifies ownership and that the conversation still exists;
   - records a minimal deletion-consent audit event without copying message content;
   - hard-deletes the conversation;
   - relies on the existing `chat_messages.conversation_id` foreign key with `ON DELETE CASCADE` to remove its messages.
6. Make the delete operation idempotent and owner-scoped. A second request must not reveal another user's records.
7. A **Keep conversation** decision records the choice and leaves all conversation and message rows unchanged.
8. Do not add a scheduled hard-delete worker or cron job for chat retention. The 90-day rule is a prompt threshold, not a deletion deadline.

## Privacy and security requirements

- Keep chat tables in the private schema with RLS enabled and direct client table access revoked.
- Expose operations only through narrowly scoped server-only RPCs granted to `service_role`.
- Do not return message content in retention eligibility or decision responses.
- Do not log prompts, message bodies, provider output, API keys, or bearer tokens.
- Keep all feature flags off until the complete release gate is explicitly approved:
  - `CHAT_ENABLED=false`
  - `DOCUMENT_UPLOAD_ENABLED=false`
  - `OCR_WORKER_ENABLED=false`
  - `DOCUMENT_QA_ENABLED=false`

## Acceptance tests

- A conversation at 89 days inactive is not prompted; at 90 days it is eligible.
- New activity updates `updated_at` and resets the inactivity period.
- A dismissed prompt or no response does not delete or modify messages.
- Choosing Keep records the decision and preserves the conversation and all messages.
- Delete without explicit confirmation is rejected and preserves all data.
- Confirmed deletion removes exactly the owner's conversation and cascaded messages.
- A different user cannot inspect eligibility, keep, or delete another user's conversation.
- Replayed deletion requests are safe and do not affect any other conversation.
- Concurrent activity and a retention decision cannot delete a conversation that has become active again.
- CI verifies the RPC grants, owner isolation, cascade behavior, and consent state transitions.

## Implementation boundary

This document records the agreed product behavior. It does not apply a database migration, deploy code, enable a feature flag, or merge any pull request. Database migration files must be generated with the Supabase CLI in a repository environment, then reviewed and tested before any staging application.
