# Kavoryn Master Completion Checklist
**Source of truth:** Kavoryn Project Plan v3.1 (12 November 2026)  
**Repository:** `kavoryn/kavorynai`  
**Status date:** 13 November 2026

This checklist tracks the complete v3.1 product scope, not just code merged to `main`.
A feature is **DONE** only when implementation and the relevant tests/evidence pass.
A successful CI build alone is not a staging, device, accessibility, accuracy, security, or release acceptance test.

## Status legend
- [x] **DONE** — implementation and the stated verification are evidenced.
- [~] **IN PROGRESS** — implementation is underway or a PR is awaiting validation/review.
- [ ] **PENDING** — not implemented or not yet verified.
- [!] **BLOCKED** — depends on a decision, environment, credential, external review, or prerequisite.
- **N/A / DEFERRED** — explicitly outside the current MVP stage; retain in Phase 2 roadmap.

Do not change a status based on an assumption. Add a test link, report, commit, or decision record when marking an item DONE.

## 0. Current release and safety position
- [x] Main repository contains Android Kotlin/Compose and PHP backend foundations.
- [x] Supabase is used for authentication, private OCR jobs/results, and consent records.
- [x] OpenAI and Gemini provider adapters exist for explanation; provider selection is explicit.
- [x] OCR result content is encrypted at rest and exposed through owner-authenticated access.
- [x] Upload, OCR worker, and document-Q&A feature flags remain OFF pending staging gates.
- [!] Verified HTTPS staging API origin and staging credentials are not yet configured.
- [!] No production deployment is authorized or claimed.
- [x] Separate public website `kavorynai.com.ng` is outside this app repository scope and must remain unchanged unless separately authorized.
- [ ] Keep this checklist synchronized with PR merges, tests, decisions, and release evidence.

## 1. Stage A — Documents (first priority)

### 1.1 Intake, scan and review
- [x] Android document picker supports PDF, JPEG, PNG, and WebP with local metadata/structure checks.
- [x] Camera capture and local review foundations exist.
- [x] Image rotation, bounded preview, and stale temporary scan cleanup exist.
- [ ] Complete camera edge detection, crop/rotate UX, and spoken scan guidance (move closer, improve light, hold steady).
- [ ] Complete multi-page PDF and image-set end-to-end coverage with page order and page-count limits.
- [ ] Add on-device image compression/resizing with measured target sizes.
- [ ] Add data-use notice and optional Wi-Fi-only upload.
- [ ] Test connection-loss recovery and safe retry without losing the user's place.
- [ ] Confirm deep-link/share-sheet document intake behavior.
- [ ] Confirm Android minimum version, 2–3 GB RAM support, and app download-size target.

### 1.2 OCR and document understanding
- [x] Provider-neutral OCR contracts cover English, Hausa, Arabic, and French.
- [x] Google Cloud Vision adapter is present; uncertainty metadata is retained.
- [x] Encrypted OCR result storage and owner-authenticated result retrieval exist in backend.
- [x] Bounded OCR queue/worker lease and retry foundations exist; worker remains disabled.
- [ ] Run fixed, permissioned/synthetic real-document corpus across all four Phase 1 languages.
- [ ] Measure OCR baseline/error rate before setting final targets.
- [ ] Specifically test Hausa characters: ɓ, ɗ, ƙ, ƴ.
- [ ] Verify multi-page provider behavior, tables, forms, columns, and complex page layouts.
- [ ] Measure OCR confidence calibration and ensure uncertain words/spans are visibly and audibly identified.
- [ ] Verify no OCR result can be read by a different user or after expiry/deletion.
- [ ] Evaluate at least one alternative OCR engine side-by-side for quality, cost, language coverage, privacy, and latency.

### 1.3 Explanation and key details
- [x] Authenticated document explanation endpoint and explicit document-specific AI consent exist.
- [x] OpenAI and Gemini explanation adapters are available with explicit provider selection.
- [x] Android result/explanation flow is implemented on merged main via PR #10.
- [ ] Verify all three explanation levels: Summary (2–3 sentences), Detailed, and What to do.
- [ ] Verify output-language selection and truthful limitations for English, Hausa, Arabic, and French.
- [ ] Implement/verify key-details panel for names, dates, amounts, numbers, and deadlines.
- [ ] Cross-check critical details against original text; never present an AI guess as fact.
- [ ] Add per-section confidence labels “Good” / “Check it” with icon + text, never colour alone.
- [ ] Keep original OCR text clearly separate and always available for verification.
- [ ] Add clear “not professional medical, legal, or financial advice” warning and professional referral wording where relevant.
- [ ] Test hallucination rate on a fixed corpus; critical names, numbers, and dates must match source.

### 1.4 Document-grounded Q&A
- [x] Backend Q&A endpoint is authenticated, owner-checked, consent-gated, and rate-limited.
- [x] Q&A provider adapters and grounded-source excerpt validation exist.
- [~] Android Q&A action is connected to completed OCR result in PR #12; PR is open and must pass CI/review before merge.
- [ ] Run authenticated staging Q&A end-to-end tests after staging origin is configured.
- [ ] Verify answer only uses the selected open document; test insufficient-information responses.
- [ ] Test prompt-injection attempts embedded in uploaded documents; document text is data, not instructions.
- [ ] Verify provider, language, and document-specific consent are explicit; no silent fallback.
- [ ] Verify no document text or answer is persisted unless a separately approved feature requires it.

### 1.5 Listen, reading comfort and accessibility
- [ ] Complete TTS end-to-end: listen, pause, replay, speed control, and high-quality voices.
- [ ] Verify TTS support/quality by Phase 1 language; label gaps honestly.
- [ ] Add TTS-synchronised text highlighting and adjustable reading/pause controls.
- [ ] Add contrast themes and plain-font option; text enlargement/zoom must not break layout.
- [ ] Respect Android reduce-motion settings.
- [ ] Ensure all controls have meaningful TalkBack labels and at least 48 dp touch targets.
- [ ] Ensure keyboard navigation for all key actions.
- [ ] Announce loading, errors, uncertainty, privacy, and consent messages accessibly.
- [ ] Complete real TalkBack and low-vision tests for Home → Review → Result.
- [ ] Test on low-end Android devices and with large text, high contrast, and reduced motion.

### 1.6 Privacy, actions and result management
- [x] Explicit consent foundations exist for OCR upload and separate AI explanation/Q&A.
- [x] OCR results are encrypted and owner-authenticated in backend.
- [ ] Add concise “What happens next” blocks to every external-processing path, including data, purpose, language, AI notice, and cancel option.
- [ ] Verify cancel at review means no data leaves the device.
- [ ] If session-level confirmation is implemented, test spoken notice and cancel window; it must reset when app closes and never apply to save/share.
- [ ] Implement Android Share Sheet for text with explicit user action and accessible labels.
- [ ] Implement instant Delete and delete-after-use end-to-end, including server objects, OCR result, temporary files, and retention expiry.
- [ ] Confirm Delete is owner-authorized, idempotent, auditable without logging private content, and clearly confirmed in UI.
- [ ] Add optional save toggle only if retention, encryption, export, and deletion behavior are defined.
- [ ] Add helpful/not-helpful feedback and Report a problem with privacy-safe diagnostic context.
- [ ] Verify privacy page covers documents, scene images, money captures, retention, processors, and deletion.
- [ ] Verify privacy default is delete-after-use; no external processing occurs without explicit confirmation.

### 1.7 Home, onboarding and languages
- [ ] Complete large, clearly labelled Home service cards for all enabled modes.
- [ ] Add short, skippable first-run introduction and optional guided first success.
- [ ] Request permissions only when needed and explain why.
- [ ] Add first-run language selection, device-language suggestion, and accessible settings.
- [ ] Decide Guest Mode; until approved, do not promise guest processing beyond existing local review.
- [ ] Store all UI strings in i18n resources; no hard-coded user-facing strings.
- [ ] Complete RTL layout and number/date handling for Arabic.
- [ ] Enable each language only after UI, OCR, AI, translation, and voice components pass testing.
- [ ] Keep English, Hausa, Arabic, and French as Phase 1; test mixed-language pages where supported.
- [ ] Keep Yoruba, Igbo, Nigerian Pidgin and other listed languages in Phase 2 unless scope is formally changed.

### Stage A exit gate — DO NOT SKIP
- [ ] Blind/TalkBack users complete Home → Review → Result unaided.
- [ ] OCR baseline and error rate measured on fixed corpus; targets documented.
- [ ] Explanation hallucination/key-detail checks pass.
- [ ] Consent, cancel, deletion, expiry, and owner-isolation tests pass.
- [ ] Accessibility issues found in real-user tests are fixed and retested.
- [ ] Stage A gate evidence reviewed and explicitly approved before treating Stage A as passed.

## 2. Stage B — Dictation and voice Chat
- [ ] Advanced AI voice dictation capture and transcription.
- [ ] Filler-word removal with user-reviewable output.
- [ ] Grammar correction and structure improvements without changing intended meaning.
- [ ] Custom dictionary for names, local terms, and specialist vocabulary.
- [ ] Reusable text snippets.
- [ ] Voice dictation available in Chat and writing flows.
- [ ] Full voice input in Chat, not only dictation cleanup.
- [ ] Document Preparation helper: lightweight guidance and optional pre-checks before full OCR/AI.
- [!] Decide whether Document Preparation is a separate screen or inline step.
- [ ] Review/confirm before any external audio or text processing; delete audio/text by default.
- [ ] Measure dictation accuracy and latency across supported languages and device conditions.
- [ ] Verify audio and dictated text are not retained in logs or provider storage contrary to policy.
- [ ] Test Chat safety, refusals, age-appropriate behavior, and prompt-injection resistance, including voice paths.

### Stage B exit gate
- [ ] Dictation accuracy and latency measured on fixed test set.
- [ ] No audio/text retained unexpectedly; deletion and consent tests pass.
- [ ] Chat safety and prompt-injection tests pass.
- [ ] TalkBack and low-vision users complete voice flows.
- [ ] Stage B gate evidence reviewed and approved.

## 3. Stage C — Scene, Money, Colour/Light

### 3.1 Scene and object description
- [ ] Scene/object mode accessible from Home and voice/Chat entry where supported.
- [ ] Camera preview with spoken/visual capture guidance.
- [ ] Review and explicit confirmation before external vision processing.
- [ ] Spoken and optional text description with honest uncertainty.
- [ ] One-tap recapture/retry.
- [ ] No image storage by default; instant delete and owner/privacy controls verified.
- [ ] Test varied lighting, clutter, motion, occlusion, and ambiguous scenes.
- [ ] Prove streaming/long-running behavior before enabling it; use single-frame fallback if hosting cannot support streaming.

### 3.2 Money and currency reader
- [ ] Camera alignment guidance and accessible capture flow.
- [ ] NGN first; explicitly decide any additional first-release currencies (USD/EUR/GBP etc.).
- [ ] Test old/new Naira notes, worn/folded notes, low light, and unusual conditions.
- [ ] Spoken denomination/currency result; optional vibration where accessible.
- [ ] Low-confidence result must say it is unsure and ask user to retry; never guess.
- [ ] Say “Confirm with another method if the amount matters.”
- [ ] No image storage by default; disclose before any online image transmission.
- [ ] Evaluate offline path; claim offline only after measured quality passes.
- [!] Set first-release currency list and numeric accuracy target before Stage C implementation/release.
- [!] Set offline Money Reader and basic TTS quality bars.
- [ ] Measure false-positive and false-negative rates on a fixed real-world test set.
- [ ] Do not publish accuracy claims without measured evidence.

### 3.3 Colour and light utilities
- [ ] Colour-name identification with uncertainty where needed.
- [ ] Approximate light-level utility with clear limitations.
- [ ] Accessible spoken output and TalkBack labels.
- [ ] Test varied lighting and device cameras.

### Stage C exit gate
- [ ] Scene and money error rates measured on fixed real-world sets.
- [ ] Money Reader meets its approved accuracy gate, including NGN priority cases.
- [ ] TalkBack-only users complete Scene and Money flows.
- [ ] Privacy, consent, deletion, and online/offline disclosure tests pass.
- [ ] Stage C gate evidence reviewed and approved.

## 4. AI Chat scope and safety
- [x] Document-grounded Q&A backend foundation exists.
- [ ] Prioritise document tasks, accessibility support, basic explanation, Phase 1 translation, and dictation.
- [ ] Clearly label broader research/business/social/technology/study/work/data/image tasks as Phase 2 unless separately approved.
- [ ] Require explicit confirmation before consequential actions such as email, payment, delete, or post.
- [ ] Refuse harmful requests and provide safe alternatives where appropriate.
- [ ] Treat uploaded document/image text as untrusted data, never as system instructions.
- [ ] Enforce plan-based usage limits server-side.
- [ ] Do not use chats for model training without explicit consent; do not write chat content to logs.
- [ ] Keep outputs age-appropriate and implement minimum-age policy after legal decision.
- [ ] Ensure Scene/Money never invent critical details and clearly signal uncertainty.

## 5. Accounts, onboarding and account lifecycle
- [x] Supabase email/password sign-in and registration foundations exist.
- [x] Encrypted Android session storage backed by Android Keystore exists.
- [ ] Complete email verification and password-reset user journeys, including expiry/replay/rate limits.
- [ ] Decide whether Google Sign-In is in MVP; if enabled, configure OAuth and redirect protection.
- [ ] Decide Guest Mode and registration timing.
- [ ] Avoid account-existence leaks in authentication errors where appropriate.
- [ ] Add robust account deletion and personal-data export flows.
- [ ] Add background session refresh and polished session-expiry UX; current refresh behavior is foreground/lifecycle-bound, not WorkManager background scheduling.
- [ ] Add encrypted-store device/instrumentation tests and sign-out/revocation failure tests.
- [ ] Define support for minimum Android version and device compatibility.

## 6. Plans, subscriptions and Paystack
- [ ] Define Free / Plus / Pro prices in NGN and USD.
- [ ] Define per-plan monthly document, page, Chat, Ask, translation, dictation, Scene, and Money quotas.
- [ ] Define file-size/page limits, priority, history/storage, trusted-helper/sharing, and support entitlements.
- [ ] Track model/provider usage and cost per successful job.
- [ ] Implement Paystack test-mode checkout before live mode.
- [ ] Support only approved one-time/recurring payment flows.
- [ ] Show exact amount and currency before charge; user explicitly chooses/confirms currency.
- [ ] Verify signed webhooks server-side for success, failure, renewal, cancellation, and refunds as applicable.
- [ ] Never trust client-side payment status; never store card details.
- [ ] Show subscription status, renewal/next billing, and cancellation clearly.
- [!] Confirm Paystack business eligibility, NGN/USD activation, settlement route, and live credentials.
- [ ] Test NGN and USD success/failure/webhook reliability before public billing.

## 7. Architecture, providers and hosting proof
- [x] Native Android Kotlin + Jetpack Compose application exists.
- [x] PHP API foundation and Supabase integration exist.
- [x] Provider adapters exist for OpenAI and Gemini explanation/Q&A.
- [ ] Complete side-by-side evaluation of at least three AI/vision providers (OpenAI, Gemini, Claude or justified alternative) for quality, Hausa/Arabic, safety, latency, cost, privacy, and rate limits.
- [ ] Evaluate OCR and TTS providers side-by-side using real Hausa/Arabic documents and voice samples.
- [ ] Confirm provider abstraction, routing, timeouts, bounded retries, and safe error handling.
- [ ] Decide and document model routing by task/plan; no silent fallback.
- [ ] Verify PHP hosting for background jobs, environment variables, private storage, SSL, multimodal routing, and streaming.
- [ ] Run PHP streaming/long-running request proof before locking architecture.
- [ ] If streaming cannot be supported, use single-frame Scene fallback and defer streaming.
- [!] Confirm final hosting environment and staging API origin; website domain is not assumed to be the API host.
- [ ] Keep all provider secrets and privileged database keys server-side; never commit secrets or put service-role keys in Android.
- [ ] Keep database to accounts/settings/usage/subscription data; no long-term document/image retention by default.

## 8. UI, brand and visual system
- [ ] Confirm final business/product name and trademark availability before public brand claims.
- [ ] Finalise logo, mode icons, and visual assets.
- [ ] Confirm Playfair Display only for large headlines; Inter for body/buttons/results; offer tested plain-font option.
- [ ] Apply palette from v3.1 with verified contrast; gold buttons use near-black text.
- [ ] Keep card corners, touch targets, spacing, and large-card Home consistent with design requirements.
- [ ] Ensure all UI strings are localised and no text is hard-coded in UI components.
- [ ] Ensure privacy note is persistently visible without creating excessive repeated warnings.

## 9. Phase 2 and later — explicitly deferred
- [ ] Trusted helper and PDF/audio sharing.
- [ ] Full offline AI.
- [ ] NaviLens-style long-range tags.
- [ ] Indoor navigation/beacons.
- [ ] Reminders and WhatsApp bot.
- [ ] Braille export.
- [ ] Additional languages from the v3.1 Phase 2/3 lists, enabled only after quality gates.
- [ ] Browser/desktop client after Android and core accessibility experience are proven.
- [ ] Broader AI Chat research/business/social/technology/study/work/data/image capabilities.
Do not let these items delay Stage A reliability or accessibility.

## 10. Security, privacy and legal readiness
- [x] HTTPS-required API clients and server-side provider-secret pattern are present in foundations.
- [x] Consent audit foundations and owner-checked OCR access exist.
- [ ] Enforce authorization on every user action and object; test cross-account access systematically.
- [ ] Separate admin/user roles with server-enforced RBAC.
- [ ] Admin MFA and immutable/auditable admin actions.
- [ ] Validate file extension, declared MIME, actual MIME, size, page count, and file structure.
- [ ] Add malware scanning or a documented risk-based upload scanning control.
- [ ] Test brute-force protection, rate limits, CSRF, XSS, SQL injection, SSRF, and prompt injection.
- [ ] Encrypt sensitive data in transit and at rest; verify key rotation and recovery plan.
- [ ] Ensure logs exclude document text, image contents, passwords, tokens, and secrets.
- [ ] Privacy notice states data collected, purpose, processors, location, retention, deletion, and user rights.
- [ ] Define minimum age, child/age-appropriate Chat handling, and parental/guardian requirements if applicable.
- [ ] Complete qualified legal review for Nigeria Data Protection Act and other target-market laws (including GDPR where applicable).
- [ ] Finalise Terms of Service and not-professional-advice disclaimers.
- [ ] Verify data processor agreements, data residency, breach response, and user notification plan.
- [ ] Test instant deletion, scheduled expiry, backup retention, and deletion propagation.
- [ ] Perform independent security review before public launch.

## 11. Accessibility and inclusive design acceptance
- [ ] Apply WCAG 2.2 as a design/testing guide.
- [ ] Meaningful screen-reader name/state for every button, card, toggle, dialog, and result action.
- [ ] Keyboard operation for every key action.
- [ ] Contrast passes; never rely on colour alone for errors/uncertainty.
- [ ] Large text and zoom do not break layout.
- [ ] Reduced-motion preference is respected.
- [ ] Loading, errors, privacy, and consent are announced to screen readers.
- [ ] Real TalkBack testing for every major mode and interactive pattern.
- [ ] Real low-vision testing, including contrast, magnification, and reading comfort.
- [ ] Include diverse users without assuming all blind/low-vision users share the same needs.
- [ ] Log issues with device, steps, expected/actual result, evidence, and owner; retest fixes.

## 12. Feedback, support and administration
- [ ] Helpful/not-helpful feedback on Document, Scene, and Money results.
- [ ] Report-a-problem flow collects only device, language, and anonymised error context by default.
- [ ] Support email and response expectations by plan.
- [ ] Help centre/FAQ and user-facing troubleshooting.
- [ ] Admin dashboard for user/usage/health/integration/error/feedback metrics only.
- [ ] Admin must never see private document or image contents.
- [ ] Dashboard roles, MFA, and audit logs enforced server-side.
- [ ] Document account deletion/export and support escalation procedures.

## 13. Monitoring, reliability and operations
- [ ] Monitor API error rates, OCR/AI/vision latency, cost per job, payment webhook health, and crashes by feature.
- [ ] Add alerts for severe errors, worker backlog, webhook failures, and unusual usage.
- [ ] Regular encrypted backups for account/settings data.
- [ ] Test restore procedure and record recovery time/objectives.
- [ ] Incident response playbook for breach/outage, including notification, user communication, and access revocation.
- [ ] Critical app update notification and force-update path for severe security issues.
- [ ] Rate limiting and abuse detection for authentication, email, upload, AI, and payment endpoints.
- [ ] Validate cleanup cron, worker lease recovery, idempotency, retries, and retention jobs in staging.
- [ ] Track cost per successful job, data cost, provider cost, and payment fees against plan budget.

## 14. Test evidence register
For every test, record: date, app/API commit, device/OS, language, test data reference, steps, expected result, actual result, Pass/Fail, evidence link, issue, owner, and retest result.

- [ ] Registration, login, email verification, password reset, session refresh, and sign-out.
- [ ] OCR across English, Hausa (ɓ/ɗ/ƙ/ƴ), Arabic/RTL, and French.
- [ ] Multi-page order, complex layout, image quality, and file validation.
- [ ] Summary/detail/action accuracy and key-number/date/name cross-check.
- [ ] TTS quality, pause/replay/speed, and highlighting.
- [ ] Dictation cleanup, custom terms, latency, and privacy.
- [ ] Scene accuracy under varied lighting/clutter and uncertainty behavior.
- [ ] Money accuracy for NGN old/new notes, worn notes, low light, and offline claims.
- [ ] Upload consent, cancel-before-send, owner isolation, retention, expiry, and deletion.
- [ ] Q&A groundedness, insufficient information, document prompt injection, and provider consent.
- [ ] Paystack NGN/USD success, failure, renewal/cancel, and webhook verification.
- [ ] TalkBack-only completion for Document, Scene, and Money; low-vision/large-text/contrast/reduce-motion.
- [ ] Connection-loss recovery, low-RAM performance, data use, battery, and app size.
- [ ] AI Chat refusal and prompt-injection resistance including voice.
- [ ] Privacy statements and “What happens next” announcements; session-confirmation cancel window.
- [ ] Cost per successful job within budget and measured payment success/webhook reliability.

## 15. Open decisions register — do not silently assume approval
- [ ] Final business name and trademark search.
- [ ] Approve Android Kotlin + PHP direction and exact database after streaming test.
- [ ] Select primary OCR and AI/vision providers after side-by-side tests, especially Hausa.
- [ ] Set Free / Plus / Pro prices and NGN/USD limits.
- [ ] Define models, tools, and usage limits per plan, including Scene/Money quotas.
- [ ] Decide Guest/no-account mode.
- [ ] Confirm document/image retention default (delete-after-use remains the default).
- [ ] Confirm hosting (background jobs, env vars, private storage, SSL).
- [ ] Confirm Paystack live eligibility, currencies, credentials, and settlement route.
- [ ] Set offline quality bar for Money Reader and basic TTS.
- [ ] Set first-release currency list and Money Reader accuracy target (blocks Stage C).
- [ ] Finalise logo, icons, and fonts.
- [ ] Decide minimum user age and data-protection compliance approach.
- [ ] Decide Document Preparation helper as separate screen or inline step.
- [ ] Decide minimum Android version, image compression targets, and app download-size limit.
- [ ] Prioritise first Phase 2 feature after Stage C.

## 16. Roadmap and release gates
- [ ] **Define:** every open decision is closed or has an owner and due condition.
- [ ] **Prove:** side-by-side OCR/AI/TTS/vision tests, Hausa/Arabic tests, Paystack test mode, PHP streaming proof, hosting confirmation, and Money Reader offline proof.
- [ ] **Build Stage A:** complete implementation and Gate A evidence.
- [ ] **Build Stage B:** complete implementation and Gate B evidence.
- [ ] **Build Stage C:** complete implementation and Gate C evidence.
- [ ] **Small-group launch:** security, accessibility, legal review, and real-user feedback fixes all pass.
- [ ] **Grow:** Phase 2 and additional languages follow measured user need and evidence.

## 17. Current pull-request watchlist (snapshot at checklist creation)
- [~] PR #11 — bounded Android OCR polling and flow documentation; open, awaiting CI/review. Do not mark merged until main contains the verified merge.
- [~] PR #12 — connect Android document Q&A to completed OCR results; open, awaiting CI/review. Q&A feature flag remains OFF.
- [x] PR #10 — Android review → upload → OCR result → explanation flow; merged to main (commit `ea33c3ead85ab83fa630d994a4e57425af5493c8`).
- [x] PR #9 — remove legacy consent RPC overload; merged migration code only. No remote migration is claimed as applied.
- [x] PR #7 — Android Q&A interface foundation; merged.
- [x] PR #6 — consent-gated grounded Q&A backend; merged.
- [x] PR #5 — TTS and Q&A backend foundation; merged.
- [x] PR #3 — Supabase email registration; merged.
- [x] PR #2 — Supabase Auth integration; merged.

## 18. Non-negotiable release rules
1. No production deployment, public launch, live payment activation, or remote database migration without explicit authorization.
2. Keep document upload, OCR worker, and Q&A flags OFF until their staging, privacy, security, and accessibility gates pass.
3. Never put provider keys, Supabase secret/service-role keys, or other privileged credentials in Android, browser code, or Git.
4. Do not use `kavorynai.com.ng` as the API origin unless separately verified and explicitly configured.
5. Do not claim feature completion from a code change or CI pass alone.
6. Do not expose private documents/images in admin dashboards or logs.
7. Keep delete-after-use as the privacy default unless the user explicitly chooses a documented save option.
8. When scope or quality conflicts, prioritise reliable document + TalkBack experience; defer later-stage features rather than ship unsafe or inaccessible behavior.

---
This checklist supplements, and does not replace, the user-approved Project Plan v3.1. Update it when implementation, test evidence, decisions, or release gates change.
