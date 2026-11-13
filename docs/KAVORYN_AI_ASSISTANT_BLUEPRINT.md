# Kavoryn AI Assistant — Capability Blueprint

## Product goal

Build Kavoryn Chat as a capable, multilingual, accessibility-first AI assistant that can understand requests, use approved tools, work with documents, remember user-approved context, and carry multi-step tasks through to verified outcomes.

The goal is broad usefulness comparable in workflow breadth to a general-purpose AI assistant. It is not a promise that Kavoryn can literally do everything, access every service, or operate without limits. Capabilities depend on configured providers, connected tools, permissions, network access, and safety constraints.

## Core capability areas

### 1. Conversation and reasoning
- Natural multi-turn conversation with context-aware follow-up.
- Task decomposition for complex requests, progress tracking, and resumable workflows.
- Clear distinction between verified facts, estimates, and unsupported requests.
- Tool selection and result synthesis, with errors reported honestly.
- English, Hausa, Arabic, and French as initial supported interface languages.

### 2. User-controlled memory
- Separate short-term conversation context from durable user memory.
- Store only useful information with a clear purpose and user-visible controls.
- Support review, correction, export, and deletion workflows.
- Respect per-item consent and requests not to retain or reuse information.
- Apply tenant/user isolation, encryption at rest and in transit, retention limits, and audit events.
- Never treat model-generated guesses as user facts.
- Never expose one user's memory to another user or to an unrelated task.

### 3. Model orchestration
- Support OpenAI and Google Gemini through server-side provider adapters.
- Explicit provider/model selection and configurable routing by task, latency, cost, and capability.
- No silent provider fallback when a user explicitly selected a provider.
- Keep API keys and elevated credentials server-side; never ship them in Android or web clients.
- Add timeouts, bounded retries, rate limits, usage accounting, and graceful provider-error handling.

### 4. Web research and knowledge
- Search current public web sources when enabled.
- Summarize and compare sources, preserve source URLs, and cite claims.
- Distinguish fresh information from model knowledge and flag uncertainty.
- Treat retrieved pages and files as untrusted data, never as instructions that override system policy.

### 5. Files and document intelligence
- Upload and securely process supported documents and images.
- OCR, extraction, summarization, translation, explanation, and grounded question answering.
- Keep OCR text encrypted and access-controlled; verify document ownership on every request.
- Ask for separate, explicit consent before sending document content to an external AI provider.
- Support accessible reading controls, adjustable text, and spoken output.

### 6. Voice and accessibility
- Speech input and text-to-speech output, including Hausa where quality is supported.
- Android TalkBack semantics, keyboard navigation, scalable text, high contrast, and RTL layouts.
- Clear playback controls and readable transcripts.
- Never make voice the only way to use a feature.

### 7. Tool and action system
- A typed tool registry with schemas, descriptions, permission scopes, and audit logs.
- Read-only tools may run within granted scopes; consequential actions require confirmation.
- Examples: calculations, coding assistance, drafting, file operations, calendar/task integrations, and supported connected services.
- Show the user what action will happen, what data will be shared, and the result.
- Do not claim an action succeeded until the connected service confirms it.
- No unrestricted shell, account access, purchases, payments, messages, or destructive operations.

### 8. Long-running workflows
- Persist task state safely so a user can resume later.
- Bounded steps, retries, cancellation, and clear progress messages.
- Human approval gates for payments, account changes, publishing, deletion, external messages, and production changes.
- Record outcome evidence and surface partial failures.

## Safety, privacy, and governance

- Authentication and authorization on every private endpoint.
- Strict user/tenant data isolation and least-privilege service credentials.
- Explicit consent for sensitive data processing and external-provider sharing.
- Data minimization, retention/deletion policy, encryption, rate limiting, abuse controls, and auditability.
- Prompt-injection defenses for web pages, documents, and tool outputs.
- No promise of unlimited knowledge, unrestricted access, or guaranteed correctness.
- The assistant must explain when a capability is unavailable or needs a connection/permission.

## Proposed implementation phases

### Phase A — Foundation
1. Define chat API contract and conversation/session model.
2. Add server-side provider orchestration for OpenAI and Gemini.
3. Add streaming responses, cancellation, timeouts, and usage/rate controls.
4. Add user-scoped conversation persistence with retention rules.
5. Add automated tests for authorization, provider errors, and isolation.

### Phase B — Memory
1. Design memory schema and user controls.
2. Add explicit save/forget preferences and consent checks.
3. Retrieve only relevant memory for the authenticated user.
4. Add review, correction, export, and deletion flows.
5. Test isolation and deletion behavior.

### Phase C — Tools and research
1. Add a typed tool registry and permission model.
2. Integrate approved web search and source citations.
3. Add safe calculation, file, and coding tools where supported.
4. Add confirmation gates and audit logs for consequential actions.
5. Test prompt injection and malicious tool output handling.

### Phase D — Voice and accessible UX
1. Build accessible chat UI and streaming states.
2. Add speech input and text-to-speech.
3. Verify Hausa, English, Arabic, French, TalkBack, RTL, large text, and contrast.
4. Add offline/error/retry states and user-controlled voice playback.

### Phase E — Workflow execution
1. Add resumable multi-step task state.
2. Add scheduling/connected-service tools only after permission and connector setup.
3. Add cancellation, bounded retries, and approval checkpoints.
4. Run end-to-end staging tests before any production release.

## Acceptance criteria

- A user can hold a multi-turn conversation and resume an authorized task.
- OpenAI and Gemini can both be selected and tested without exposing credentials.
- Memory is user-scoped, consent-aware, reviewable, correctable, and deletable.
- Web research returns source links and does not present unsupported claims as facts.
- Tools have typed inputs, scoped permissions, visible confirmations, and auditable outcomes.
- Documents remain private, owner-checked, encrypted, and separately consent-gated for external AI.
- The chat remains usable with TalkBack, large text, high contrast, RTL, and supported languages.
- Automated tests cover access control, data isolation, prompt injection, provider failures, and destructive-action confirmation.
- Staging verification is complete before enabling any production feature flag.

## Explicit non-goals

- Copying another product's private model weights, internal memory, prompts, or proprietary systems.
- Claiming that the assistant can perform literally any action in the world.
- Unrestricted access to user accounts, devices, money, private data, or production infrastructure.
- Enabling uploads, OCR workers, document Q&A, or production deployment without the required staging tests and explicit authorization.

## Current project constraints

- Keep existing document upload, OCR worker, and document Q&A feature flags OFF until staging verification.
- Do not apply remote Supabase migrations or deploy production services as part of this blueprint.
- Do not put provider secrets or Supabase elevated keys in client code or Git.
- Treat this document as a product/engineering blueprint; implementation must be delivered in small, tested pull requests.
