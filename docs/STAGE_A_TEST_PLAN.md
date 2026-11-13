# Stage A — test and evidence plan

Status: test plan draft; no test result is claimed yet.  
Source: Kavoryn Project Plan v3.1.

## Test record format

For every case record: test ID, date, app/API version, device and Android version, language, fixture ID, steps, expected result, actual result, Pass/Fail/Blocked, evidence link, tester, defect ID. Do not store private user documents in test evidence; use synthetic or explicitly consented fixtures.

## Fixed document corpus

Prepare a versioned, synthetic or permission-cleared corpus containing:
- English, Hausa, Arabic and French.
- Hausa letters ɓ, ɗ, ƙ, ƴ in body text, names and forms.
- Arabic RTL paragraphs, mixed Arabic/Latin digits and mixed-language pages.
- Single-page photos, multi-page PDFs, skewed/blurred/low-light images.
- Official-looking forms, tables, multi-column layouts, dates, amounts, names, IDs and deadlines.
- Ambiguous characters and deliberately unreadable spans for uncertainty tests.

Keep ground-truth transcription and key-detail annotations separate from app output. Do not claim accuracy until scored against this corpus.

## Local intake and scan tests added for the current Android foundation

| ID | Scenario | Expected |
|---|---|---|
| INTAKE-001 | Open the document picker and select PDF/JPEG/PNG/WebP | Supported file metadata is displayed |
| INTAKE-002 | Select an unsupported or unverifiable MIME type | File is rejected with a readable message |
| INTAKE-003 | Select an empty file | File is rejected |
| INTAKE-004 | Capture a page with the system camera | Image is saved to app cache and shown in review |
| INTAKE-005 | Cancel camera capture | No document is added; user receives a clear message |
| INTAKE-006 | Inspect a valid PDF | Local PDF page count is displayed |
| INTAKE-007 | Inspect a damaged/unreadable PDF | Safe validation error is displayed |
| INTAKE-008 | Inspect a valid image | Pixel dimensions are displayed |
| INTAKE-009 | Inspect a low-resolution image | Resolution warning is displayed |
| INTAKE-010 | Confirm or cancel review | No network request or external processing occurs |
| INTAKE-011 | Rotate a large JPEG/PNG/WebP image | A readable 90-degree derivative is created without changing the picker original |
| INTAKE-012 | Rotate a transparent PNG/WebP | Transparent areas render against white in the JPEG derivative |
| INTAKE-013 | Rotate a corrupt/unsupported image payload | A clear error appears; partial cache output is removed |
| INTAKE-014 | Cancel camera capture | The empty temporary camera file is removed |
| INTAKE-015 | Replace a scan selection with picker files | Previous app-owned temporary scan files are removed; picker originals remain untouched |
| INTAKE-016 | Relaunch after force-stop during scan | Abandoned Kavoryn scan cache files older than 24 hours are removed at startup |
| INTAKE-017 | Review an image or PDF | A bounded preview appears without blocking the UI |
| INTAKE-018 | Select a structurally damaged document | Confirm is disabled until invalid files are removed or replaced |
| INTAKE-019 | Review multiple large images on a low-RAM phone | Preview memory remains bounded; scrolling and TalkBack remain usable |
| INTAKE-020 | Camera returns a damaged image | File is rejected and temporary camera output is deleted |
| INTAKE-021 | One file in a multi-file review is invalid | User can remove the invalid file and continue with valid files |

These are test cases, not recorded pass results. Camera crop, blur/glare detection and multi-page camera capture remain unimplemented. Rotation, preview and cache-cleanup code are present but these test cases have not been executed.

## Functional tests

| ID | Scenario | Expected |
|---|---|---|
| DOC-001 | Capture a clear single page | Scan guidance and review screen appear |
| DOC-002 | Select a multi-page PDF | All pages are accounted for in order |
| DOC-003 | Select a set of images | Pages are ordered and previewable |
| DOC-004 | Unsupported or malformed file | Safe rejection; no external processing |
| DOC-005 | Poor lighting / blur / skew | Helpful spoken and visual retake guidance |
| DOC-006 | OCR with unclear words | Uncertain spans are marked; no silent guess |
| DOC-007 | Choose Hausa output | UI/result language follows selected choice where supported |
| DOC-008 | Summary | 2–3 sentences grounded in original OCR |
| DOC-009 | Detailed | Expanded explanation does not add unsupported facts |
| DOC-010 | What to do | Steps are grounded; advice disclaimer remains visible |
| DOC-011 | Key details | Dates, amounts, names, numbers and deadlines trace to original spans |
| DOC-012 | Listen | Play, pause, replay and speed controls work |
| DOC-013 | Ask a question | Answers only from current document; unsupported question gets clear limitation |
| DOC-014 | Share text | Android share sheet opens only after user action |
| DOC-015 | Delete | Original/temp files and generated artifacts are removed |

## Consent and privacy tests

| ID | Scenario | Expected |
|---|---|---|
| PRIV-001 | Cancel at review screen | No document bytes leave device |
| PRIV-002 | No consent token | API rejects upload |
| PRIV-003 | Expired consent | API rejects upload |
| PRIV-004 | Replayed token | API rejects second use |
| PRIV-005 | Payload digest mismatch | API rejects upload |
| PRIV-006 | Session remember disabled | Full review is required on next process action |
| PRIV-007 | App closed and reopened | Remembered confirmation is reset |
| PRIV-008 | Save/share action | Separate explicit confirmation required |
| PRIV-009 | Processing succeeds | Temporary content is deleted according to default |
| PRIV-010 | Provider/API timeout | Temporary content is cleaned up |
| PRIV-011 | User cancels in progress | Job is cancelled where possible and artifacts cleaned |
| PRIV-012 | Logs/analytics/audit | No document text, image, password, token or secret appears |

## Security tests

- Cross-account access to job, result, delete, consent and usage resources is denied.
- Client-supplied user ID, plan, quota, subscription status and payment status are ignored.
- MIME signature, extension, size, page count, malformed PDF and decompression limits are checked.
- Uploads use randomized temporary names outside public web root.
- Authentication, upload, OCR, AI and email endpoints are rate-limited.
- SQL is parameterized; output is encoded; CSRF protection applies to cookie-authenticated admin/web routes.
- Provider prompt injection in uploaded document content cannot override system policy or trigger tools.
- Error responses expose no stack trace, filesystem path, secret, or provider credential.
- Admin access is separated, MFA-protected and audited; admin cannot view private documents.

## Accessibility tests

- TalkBack user completes Home → Review → Result unaided.
- Every card, control, toggle and icon has a meaningful accessible name and state.
- Focus order is logical; keyboard operation is supported.
- Large text and display scaling do not clip or hide actions.
- Contrast meets the plan; uncertainty and errors are not communicated by colour alone.
- Reduced motion is respected.
- Loading, failure, consent and privacy statements are announced.
- Touch targets are at least 48 dp.

## Performance and data tests

Test at least two low-end Android devices, including 2–3 GB RAM class devices. Record app size, memory, upload bytes, processing latency, retry behavior and data consumed per successful document. Test interrupted network, airplane mode, weak connectivity, Wi-Fi-only preference, and safe retry without duplicate charges/jobs.

## Release gate

Stage A is not approved until all critical security/privacy/accessibility cases pass, OCR error baseline and key-detail accuracy are measured on the fixed corpus, the TalkBack end-to-end journey passes with real users, and all open critical defects are closed. A mock, screenshot, or happy-path demo is not evidence of completion.


## OCR contract unit tests

| ID | Test | Expected |
|---|---|---|
| OCR-001 | Parse en/ha/ar/fr language codes | Stable codes resolve; unsupported codes are rejected |
| OCR-002 | Normalize Hausa text | ɓ, ɗ, ƙ and ƴ remain unchanged |
| OCR-003 | Inspect Arabic language metadata | Arabic is marked right-to-left |
| OCR-004 | Validate uncertainty spans | Out-of-range spans are rejected |
| OCR-005 | Validate word confidence | Values outside 0..1 are rejected |
| OCR-006 | Validate word geometry | Partial, out-of-range or inverted boxes are rejected |
| OCR-007 | Join multi-page text | Pages are returned in page-number order |
| OCR-008 | Run fixed language text fixtures | English, Hausa, Arabic and French Unicode is preserved |

Automated OCR contract and coordinator unit tests are executed by the Android CI workflow. The latest workflow run (43475751689) reported the unit-test step as successful. This does not measure real OCR accuracy: that still requires a fixed permissioned/synthetic scan corpus and selected provider adapters.


## OCR submission coordinator tests

| ID | Scenario | Expected |
|---|---|---|
| OCR-SUB-001 | User declines external processing | No consent request or upload occurs |
| OCR-SUB-002 | User confirms valid PDF/image | Stream digest is bound to selected language and one-time consent grant before upload |
| OCR-SUB-003 | Unsupported MIME | Rejected before any network call |
| OCR-SUB-004 | Empty/unreadable input stream | Rejected before consent request |
| OCR-SUB-005 | Consent endpoint returns no grant or blank token | Upload is not attempted |
| OCR-SUB-006 | Retry with same idempotency key | API must return the same job outcome without duplicate processing |
| OCR-SUB-007 | Coroutine is cancelled | Cancellation propagates and is not converted into a retryable network error |

Coordinator and authenticated HTTPS gateway unit tests are executed by Android CI. The concrete gateway exists, but Android sign-in/session wiring and staging API configuration are not yet connected to the app UI. End-to-end server-side digest, upload, queue, OCR, expiry and deletion behavior must still be verified in staging before enabling upload.


## Output language selection UI tests

| ID | Scenario | Expected |
|---|---|---|
| LANG-001 | Open language selector on Home | English, Hausa, Arabic and French are available |
| LANG-002 | Choose Hausa then select a document | Review screen shows Hausa as the output language |
| LANG-003 | Choose Arabic | Arabic language code and RTL metadata are selected |
| LANG-004 | Change selection before processing | The latest selected language is used for the consent request |
| LANG-005 | Use TalkBack on language selector | Control announces its label, current value and selectable options |
