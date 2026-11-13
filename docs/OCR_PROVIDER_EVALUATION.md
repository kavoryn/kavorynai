# OCR provider evaluation — Stage A

Status: Google Cloud Vision selected for the first controlled adapter; production accuracy gate remains open  
Scope: English, Hausa, Arabic and French

## Non-negotiable design

- Keep the OCR engine behind the Android `OcrEngine` interface and a matching server-side adapter contract.
- The Android app must not contain provider API keys.
- Keep original OCR text separate from any AI explanation or translation.
- Preserve Unicode exactly, including Hausa `ɓ, ɗ, ƙ, ƴ` and Arabic combining marks.
- Arabic layout must preserve logical text order and render right-to-left in the UI.
- Mark uncertain spans; never silently invent unreadable words.
- Provider choice requires comparative tests on the same fixed document set.
- Do not upload real personal documents during provider selection without explicit informed consent.

## Phase 1 language fixtures

The following are synthetic text fixtures for Unicode and display tests. They are not OCR accuracy evidence.

| Language | Code | Fixture |
|---|---|---|
| English | en | Please read this document carefully. |
| Hausa | ha | Ƙarɓi: ɗalibi ya sayi ƙwai da ɓawon lemu. |
| Arabic | ar | يرجى قراءة هذه الوثيقة بعناية |
| French | fr | Veuillez lire attentivement ce document. |

## Required scan corpus before provider selection

Create a permissioned, non-sensitive corpus with:
- Printed and photographed documents for all four languages.
- Clean, low-light, skewed, shadowed, blurred and folded examples.
- Multiple fonts and font sizes; Hausa diacritics and special letters.
- Arabic RTL paragraphs, mixed digits, punctuation, tables and forms.
- One-page and multi-page PDFs, scanned PDFs and raster images.
- Ground-truth transcription checked by fluent readers; two reviewers for critical names, dates and amounts.

Never use synthetic text-only fixtures as proof of OCR accuracy.

## Scorecard

Run each provider on the exact same corpus and record:
- Character error rate (CER) and word error rate (WER) per language.
- Exact preservation rate for Hausa `ɓ, ɗ, ƙ, ƴ`.
- Arabic character/order correctness, including punctuation and digits.
- Critical-field accuracy for names, dates, amounts and reference numbers.
- Page segmentation and reading order for multi-column/forms.
- Confidence calibration and usefulness of uncertain spans.
- Latency per page, failure rate, retry behavior and estimated cost per successful page.
- Data residency, retention, provider-side deletion, training use, encryption and contractual terms.
- Rate limits, SDK/API stability, offline behavior and provider-switching cost.

Do not combine language scores into one average that hides weak Hausa or Arabic performance. Publish per-language results internally.

## Provider decision gate

Google Cloud Vision is the initial implementation candidate, not a claim that it has already passed the quality gate.

Published language support distinguishes three tiers:
- Arabic, English and French are listed as supported languages that Google prioritizes and regularly evaluates.
- Hausa (`ha`) is listed as a mapped language using the Latin character model. It is more prone to misidentification than a fully supported language.
- The adapter omits language hints so the document OCR model can auto-detect mixed language content.

The provider decision becomes production-approved only after:
1. Legal/privacy review confirms acceptable processing terms and the chosen EU regional endpoint.
2. The same permissioned corpus is run through Google Cloud Vision and at least two alternatives.
3. Hausa and Arabic results meet product-approved accuracy thresholds.
4. Critical values can be cross-checked against source regions.
5. Deletion and failure paths are demonstrated.
6. Cost and latency fit the target low-data/low-end-phone experience.

Thresholds must be approved after measuring the baseline; do not invent a public accuracy claim.

## Current implementation

- Android provider-neutral data contracts are in `app/src/main/java/com/kavoryn/app/ocr/OcrContracts.kt`.
- Unit tests cover language codes, Hausa Unicode preservation, Arabic direction metadata, confidence/geometry/span validation and page ordering.
- The Android app still does not call Google Vision directly; provider credentials remain on the PHP server.

## Backend provider implementation

The PHP API now has a Google Cloud Vision adapter under `backend/src/Ocr/`:

- `GoogleServiceAccountTokenProvider` signs OAuth JWTs server-side and caches access tokens only within the worker process.
- `GoogleCloudVisionOcrProvider` calls `DOCUMENT_TEXT_DETECTION`, auto-detects languages, parses detected language metadata, and marks words with confidence below 0.80 as uncertain.
- PDF pages are rendered locally with Poppler and sent in bounded synchronous image batches; source PDFs are not copied to a separate cloud bucket.
- The default endpoint is Google's EU regional endpoint. Credentials remain in a server-only JSON file with owner-only permissions.
- `SupabaseEncryptedOcrResultSink` encrypts OCR JSON with libsodium secretbox before persistence. `SupabaseEncryptedOcrResultReader` checks ownership, decrypts and verifies the keyed HMAC.
- The CLI worker is `backend/bin/ocr_worker.php`; it is disabled by default until the host is configured.

Mocked provider, worker, sink and reader tests are part of the PHP CI workflow. They verify integration contracts, not real provider accuracy. The provider-selection quality gate remains open until a permissioned corpus is measured per language, especially Hausa and Arabic, and privacy/retention terms are approved.
