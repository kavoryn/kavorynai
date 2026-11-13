# Kavoryn — Project Plan v3.1: implementation requirements

Source: user-provided **Kavoryn — Project Plan v3.1 (full, merged)**, dated 12 November 2026. This file records the product scope and constraints for implementation. The source document remains authoritative; unresolved items below must not be silently treated as approved.

## 1. Vision and principles

Kavoryn helps people read, understand, and listen to documents in their own language, especially blind, low-vision, and other people who find documents hard to read. It grows into an accessibility companion with advanced dictation, scene/object description, money reading, and colour/light utilities.

Core document flow: photo or upload → OCR → plain-language explanation in three levels → read or listen. AI Chat supports related tasks. Scene, Money, and Dictation modes sit alongside the document pipeline.

Build order: Android app and PHP backend first, then website, payments, and fuller admin panel, driven by testing and user need.

UX principles: large service cards; explicit privacy statements; optional feature toggles; “What happens next” blocks; review-before-process. Nothing is sent until the user confirms. Privacy default is delete-after-use. Screen readers, large text, and keyboard navigation must work. Support low-end phones and limited data. Real users must test before public claims.

## 2. Users and problems

Users include people who struggle with small text or long documents; blind and low-vision users including TalkBack users; people who prefer another language; people facing official, academic, banking, or legal wording; hands-free input users; people needing quick banknote/object/scene identification; Android users first and browser users later; and people who prefer listening. Do not assume all users in these groups have identical needs.

## 3. Differentiation and boundaries

- Document-grounded explanation in Summary / Detailed / What to do; avoid invented facts and signal confidence as “Good” / “Check it”.
- Multi-language focus from day one, including Hausa and special-character testing.
- Privacy-first, delete-after-use, consent before external AI/OCR.
- Document-grounded Q&A only from the open document.
- Advanced dictation, scene description, money reading, accessibility across every feature.
- Original text always available for verification.
- No professional medical, legal, or financial advice claims.
- No human volunteer video-call service.

## 4. Release stages and gates

### Stage A — Documents (first priority)
- Camera scan or image/PDF upload, multi-page PDFs and image sets.
- OCR with uncertain words flagged.
- Plain-language explanation: Summary (2–3 sentences), Detailed, What to do.
- Key details: dates, amounts, names, numbers, deadlines.
- Per-section confidence: “Good” / “Check it”.
- Output-language selection.
- Text-to-speech: listen, pause, replay, speed control; high-quality voices.
- Scan guidance such as move closer / add more light.
- Reading comfort: TTS-synced highlighting, adjustable pause, contrast themes.
- Better handling of tables, forms, multi-column layouts and complex pages.
- TalkBack, keyboard, large text, contrast, reduced motion.
- Privacy page, instant delete, delete-after-use default.
- Document-grounded Ask a question; Android share sheet for text.
- Large labelled Home service cards.
- “What happens next” before external steps.
- Review-before-process confirmation.
- Optional explicit toggles such as translation and saving.
- Persistent privacy messaging.

**Gate A:** TalkBack users complete Home → Review → Result without help; OCR and hallucination rates measured on a fixed test set; privacy and delete tests pass.

### Stage B — Dictation and voice Chat
- Advanced AI voice dictation: filler removal, grammar correction, custom dictionary, snippets, for Chat and writing.
- Full voice input in Chat, not only dictation cleanup.
- Document Preparation helper: light guidance and optional pre-checks before full OCR/AI.

**Gate B:** dictation accuracy and latency measured; no audio or text retained; Chat safety and prompt-injection tests pass.

### Stage C — Scene, Money, Colour/Light
- Scene and object description with spoken description.
- Money/currency reader: NGN priority, then major currencies; offline path preferred where quality allows.
- Colour name and approximate light-level utilities.

**Gate C:** scene and money error rates measured on fixed real-world sets; Money Reader meets its accuracy gate; TalkBack-only completion of both flows.

### Phase 2 / Later
Trusted helper and PDF/audio sharing; full offline AI; NaviLens-style long-range tags; indoor navigation/beacons; reminders, WhatsApp bot, Braille export. Do not let these delay the staged MVP.

## 5. AI Chat

Prioritise document tasks, disability support, basic explanation, translation between Phase 1 languages, and high-quality dictation. MVP examples include documents/files, disability support, advanced voice input, learning, writing/editing, and translation. Broader research/business/social/technology/study/work/data/images remain Phase 2; daily-life automation and custom agents are later.

Before any consequential action (email, payment, delete, post), require user confirmation. Harmful requests are refused. Uploaded document/image text is data, not instructions. Usage limits apply by plan. Chats are not used for model training without consent, and chat content is not written to logs. Keep answers age-appropriate if the user may be a child. Scene and money outputs must signal uncertainty and never invent critical details.

## 6. Onboarding and first run

Short skippable introduction to Documents, Scene, Money, Dictation and delete-after-use. Ask permissions only when needed and explain why. Camera permission reason covers scan, scene and money. Early language selector with device language preselected when possible. Optional guided first success. Accessibility settings reachable from first session. Guest mode remains open decision; otherwise prompt registration after first success.

## 7. UI and visual system

English is default UI language. All UI strings live in i18n files, never hard-coded. Home has large tappable cards such as Read a document, Describe what I see, Read money, Voice dictation / Chat. Keep privacy note visible: documents/photos are deleted after use unless user chooses to save.

Before external AI/OCR, explain what will be sent, language, that output is AI-generated, and that the user can cancel. Explicit Continue / Send for explanation required. First use in a session gets full spoken block and Confirm button. Optional session remember toggle is OFF by default; after confirmation in same session, later captures may use a short spoken notice and a visible Cancel window. It resets when app closes and never applies to save/share. Server records each confirmation without content; confirmation token remains required.

Results separate original text from simplified explanation; uncertainty uses icon plus text, not colour alone. Result offers Listen and Delete and “not professional advice” warning. Scene/Money announce outcome and offer Try again. Scan has automatic edge detection and spoken guidance. Chat answers have Listen, voice input and clear errors. Interactive controls at least 48 dp and WCAG 2.2 contrast.

Typography: Playfair Display for large headlines only; Inter for body/buttons/results; plain-font option offered and tested. Rounded corners 14–18 px. Primary buttons at least 48 px. Gold buttons use near-black text (#1A1814), not white.

### Palette
- Page background: light blue #E8F1FB
- Cards / inputs: lighter blue #F4F9FE
- Borders: pale blue #CFE0F2
- Main button / active: gold #C9A24B
- Gold text: dark gold #7A5509 (only where contrast passes on light blue)
- Primary text: near-black #1A1814
- Secondary text: cool grey #55606E

## 8. Architecture direction (not locked until proof)

Clients: native Android (Kotlin + Jetpack Compose); website later (PWA possible); HTML admin dashboard with MFA.

PHP API is the only door to database and external services. It handles authorization, file intake, limits, audit log, payment webhooks and AI/OCR routing including multimodal. API keys stay server-side. Database stores accounts, settings, usage and subscription status only; no long-term document/image storage by default. Outside services may include text/vision AI, OCR, TTS, email, Paystack and Google Sign-In.

Admins see usage figures/settings, never private documents or images. Select providers after tests for quality, cost, language support (especially Hausa and Arabic), privacy and switchability. Route simple tasks to cheaper models and complex tasks to stronger models.

Real-time Scene streaming must be tested during Prove phase (queues, long-running requests, WebSockets or helper service). If PHP/hosting is unsuitable, Stage C starts with single-frame capture and streaming is later. Lock stack only after test and hosting confirmation.

## 9. Core flows

### Document
1. Sign in, or guest if approved; choose Read a document (also deep link/share entry).
2. Scan with spoken/visual guidance or pick single/multi-page file.
3. Check type, size, page count, image quality; compress images on device first.
4. Optional crop/rotate/Also translate.
5. Review what will be processed and privacy reminder; Cancel sends nothing.
6. OCR; mark unclear text uncertain.
7. Choose result language.
8. Generate Summary, Detailed, What to do without guesses presented as facts.
9. Cross-check key numbers, dates, names against original.
10. Show key details at top; original and simplified text separate; confidence flags; Listen, Ask, Share, Delete.
11. Delete file/result after use unless explicitly saved.

### Scene / Object
Choose mode or ask by voice/Chat; camera preview and spoken guidance; capture or stream only if proven; review/confirm external model call; multimodal spoken/optional text description; flag uncertainty; one-tap recapture; ephemeral result by default.

### Money
Camera alignment guidance; identify denomination and currency (NGN first, then USD/EUR/GBP and other common notes). Spoken result and optional vibration. Prefer offline if quality permits; disclose before online image transmission. No image storage by default. Set first-release currencies and accuracy target before Stage C. Test old/new NGN designs, worn/folded/low-light notes. Say low confidence aloud and ask to retry; never guess. Say “Confirm with another method if the amount matters.” Public accuracy claims only from measured results.

### Dictation
Hands-free input; filler removal, grammar fix, custom dictionary, snippets. Clear entry card and input guidance; review/confirm before external calls; spoken plus optional text result; easy retry; delete by default.

## 10. Languages

Enable a language only after UI, OCR, AI, translation and voice (including Scene/Money voice) pass testing.

- Phase 1 MVP: English, Hausa, Arabic, French. Test Hausa characters ɓ, ɗ, ƙ, ƴ specifically.
- Phase 2: Spanish, Portuguese, Mandarin Chinese, Hindi, German, Italian, Russian, Japanese, Korean, Turkish, Indonesian, Swahili, Bengali, Urdu, Yoruba, Igbo, Nigerian Pidgin. Label Pidgin “limited support” if quality is insufficient.
- Phase 3: Vietnamese, Thai, Polish, Ukrainian, Dutch, Greek, Hebrew, Persian, Malay, Tagalog, Romanian, Hungarian, Czech, Swedish, Finnish, Norwegian, Danish, Slovak, Bulgarian, Croatian, Serbian, Slovenian, Latvian, Lithuanian, Estonian, Catalan, Afrikaans, Zulu, Xhosa, Amharic, Somali, Oromo, Kinyarwanda, Luganda, Shona, Cantonese, Javanese, Marathi, Telugu, Tamil, Gujarati, Kannada, Malayalam, Punjabi, Burmese, Khmer, Lao, Nepali, Sinhala, Pashto, Kurdish, Azerbaijani, Uzbek, Kazakh, Georgian, Armenian, and others by demand.

Default UI language English; UI/result/Chat languages may differ. RTL for Arabic/Urdu/Hebrew/Persian. Dates/numbers follow region. Support mixed-language pages where OCR allows. Label incomplete components honestly.

## 11. Accounts and sign-in

Email/password with hashed passwords, never plaintext; email verification by link or one-time code; password reset by email. Optional Google Sign-In only when OAuth and redirect URLs are correctly protected. Rate-limit sign-in and email sending. Avoid account-existence leaks in errors where risky. Collect only useful data. Guest mode is undecided.

## 12. Plans and Paystack

Tiers: Free, Plus, Pro. Paystack direction supports NGN and USD. Prices and limits are undecided. Free gets small monthly document, money and scene allowance. Plus standard; Pro higher/priority. Exact document counts, file/page limits, AI Chat/Ask/Translation/Voice quotas and support levels remain to plan. History/storage: Free minimal/none, Plus limited, Pro extended. Sharing/trusted helper: none/basic/full respectively. Support: community/email, email priority, priority.

Support one-time and recurring payments if subscriptions are used. Show exact amount/currency before charge; user chooses/confirms currency. Secure backend webhooks for success/failure/renewal/cancellation. Never trust client payment status. Store only necessary subscription/reference data, never card details. Test mode before live. Clear cancellation and next-billing display. Nigerian settlement is typically NGN; USD acceptance/settlement may need activation and domiciliary account—verify current provider rules for the chosen business entity before launch.

## 13. Providers and technology evaluation

Direction: Kotlin + Compose Android, PHP API, database for accounts/settings/usage/subscription only. Verify hosting supports background jobs, environment variables, storage, SSL, multimodal routing and streaming.

Evaluate at least three AI providers including vision:
- OpenAI (GPT-4o / mini): explanation, summary, Chat, translation, vision.
- Google Gemini: explanation, Chat, multimodal.
- Anthropic Claude: complex explanation and long-context Chat.

Compare quality, cost, Hausa/Arabic, latency, safety/refusal, vision accuracy and rate limits. Abstract provider routing to allow switching/fallback. Track cost per successful job. OCR engines and TTS providers also need side-by-side testing with real Hausa/Arabic documents and voice.

## 14. Low-end phones, data and cost

Compress/resize images on-device; set target size by testing. Choose minimum Android version and test 2–3 GB RAM phones. Keep app small; no large Stage A bundled models. Show data use before large uploads; offer Wi-Fi-only. Recover connection loss without losing place; retry with spoken messages. Track data cost per job as well as AI cost.

## 15. Feedback and support

Helpful/not-helpful feedback on document, scene, money results; optional free text. Report-a-problem captures device, language and anonymised error context only; never private image/text unless user attaches it. Support email now, help centre later, response targets by plan. Admin dashboard shows newest reports and error rates by feature.

## 16. Privacy, security and legal

HTTPS everywhere; no API keys/secrets in app. Server authorization for every action. Separate admin/user roles; MFA for admins; every admin change audited. Validate file type, size, extension and actual MIME; protect uploads from malware. Rate-limit and protect against brute force, CSRF, XSS, SQL injection. Encrypt sensitive data in transit/at rest. Minimal logs: no document text, image contents, passwords or tokens.

Clear privacy notice: collected data, purpose, retention, deletion; covers document, scene and money images. Explicit consent before external AI/OCR enforced in review-before-process. Instant delete. Legal review (GDPR, Nigeria Data Protection Act, etc.) with qualified lawyer before launch. Decide minimum age and age-appropriate Chat, data location and processors. Terms and professional-advice warnings.

## 17. Accessibility

WCAG 2.2 is the guide; real TalkBack and low-vision testing is proof. Every control and mode has meaningful screen-reader name. Key actions keyboard-operable. Contrast sufficient; never use colour alone for uncertainty/errors. Text enlargement/zoom must not break layout. Respect reduce-motion. Announce loading, errors and privacy statements. TalkBack test Home → Review → Result for each major mode before public release.

## 18. Test plan and success measures

Record each test with date, device, steps, expected/actual, Pass/Fail, evidence, owner.
Test registration/login; OCR all Phase 1 languages especially Hausa characters; multi-page; summary accuracy/no invented facts; key detail cross-check; scan guidance; TTS/dictation; scene under varied lighting/clutter; NGN old/new money and offline claims; upload validation/deletion for document/scene/money; admin isolation; Paystack NGN/USD success/failure/webhooks; connection recovery; low-RAM/performance/data/cost; Chat refusal and prompt-injection including voice.

UX tests: every external-processing path has “What happens next” and confirmation; cards/toggles TalkBack-operable; privacy statements announced; cancel at review means no data leaves device; cancel during short session countdown means no data leaves device.

Success targets: new user completes upload→understand→listen unaided; TalkBack user completes Scene and Money with TalkBack only; OCR error baseline measured on fixed set before target set; critical numbers/dates/names match source; scene/money error measured on fixed real-world sets; accessibility issues fixed before launch; cost per successful job within budget; payment/webhook reliability tracked NGN/USD.

## 19. Operations

Monitor API errors, OCR/AI/vision latency, cost per job, payment webhook health and crash reports by feature. Regular encrypted backups and tested restore. Incident response for breach/outage: notification, user communication, access revocation. Critical app updates and force-update path for severe security issues. Rate limits and abuse detection on auth, upload, AI.

## 20. Roadmap and exit conditions

1. Define: open decisions closed or assigned owner.
2. Prove: side-by-side OCR/AI/TTS/vision tests including Hausa/Arabic; Paystack test mode; PHP streaming decision; hosting confirmed; money offline path tested.
3. Build Stage A and pass Gate A.
4. Build Stage B and pass Gate B.
5. Build Stage C and pass Gate C.
6. Small-group launch only after security, accessibility, legal review and real-user feedback fixes.
7. Grow Phase 2 and more languages/offline support based on evidence.

## 21. Risks

Large MVP scope: staged gates, defer later work if quality suffers. OCR/AI errors: uncertainty, original text, cross-check, test sets. Scene hallucination: uncertainty, recapture, fixed tests. Money error: strict accuracy gate, NGN testing, alternate confirmation. Review fatigue: short spoken reviews and optional session confirmation with cancel window. Privacy: consent gates, delete-by-default, minimal storage. TalkBack regressions: mandatory user testing. Language gaps: honest limited-support labels. Cost: quotas, routing and per-job tracking. Weak phones/data: compression, Wi-Fi-only and low-RAM testing. Payment/currency: test mode, explicit currency, verified webhooks. Hosting/PHP streaming: prove before lock. Professional reliance: clear warnings and contact-professional prompts. Data-protection risk: legal review per market.

## 22. Open decisions — must remain visible

1. Final business name and trademark search.
2. Approve Android Kotlin + PHP and exact database after streaming test.
3. Primary OCR and AI/vision providers after side-by-side tests, especially Hausa.
4. Free/Plus/Pro prices and NGN/USD limits/costs.
5. Models, tools and usage limits per plan including Scene/Money quotas.
6. Guest/no-account mode.
7. Document/image retention default (currently delete-after-use).
8. Hosting confirmation (jobs, env vars, storage, SSL).
9. Paystack live credentials, currency activation and settlement path.
10. Offline quality bar for Money Reader and basic TTS.
11. First-release currencies and Money Reader accuracy target (blocks Stage C).
12. Final logo, icons and fonts.
13. Minimum user age and data-protection compliance approach.
14. Document Preparation helper as separate screen or inline step.
15. Minimum Android version, image compression targets and download-size limit.
16. First Phase 2 feature after Stage C.

## Source change note

Version 3.1 dated 12 November 2026 takes precedence over earlier versions. It split MVP into Stages A–C with gates; added session-level confirmation; moved Yoruba, Igbo and Pidgin to Phase 2; added low-end/low-data requirements; strengthened Money Reader safety gate; required PHP streaming proof; renamed tiers Free/Plus/Pro; and updated visual language and roadmap.
