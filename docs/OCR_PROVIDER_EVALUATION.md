# OCR provider evaluation — Stage A

Status: evaluation plan; no OCR provider selected  
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

A provider remains unselected until:
1. Legal/privacy review confirms acceptable processing terms.
2. The same permissioned corpus is run through at least three candidates.
3. Hausa and Arabic results meet product-approved accuracy thresholds.
4. Critical values can be cross-checked against source regions.
5. Deletion and failure paths are demonstrated.
6. Cost and latency fit the target low-data/low-end-phone experience.

Thresholds must be approved after measuring the baseline; do not invent a public accuracy claim.

## Current implementation

- Android provider-neutral data contracts are in `app/src/main/java/com/kavoryn/app/ocr/OcrContracts.kt`.
- Unit tests cover language codes, Hausa Unicode preservation, Arabic direction metadata, confidence/geometry/span validation and page ordering.
- No OCR provider SDK is installed and no real OCR request is made yet.


## Backend provider boundary (implemented foundation)

The PHP API now has a provider-neutral contract under `backend/src/Ocr/`:

- `OcrRequest` accepts only readable server-side files, the supported PDF/image MIME types, and `en`, `ha`, `ar`, or `fr`.
- `OcrProvider` defines the server-side `recognize()` boundary. Vendor SDKs and credentials must remain behind this interface.
- `OcrResult` preserves the original UTF-8 text and validates uncertainty spans as Unicode character offsets, plus an optional detected-language code.
- `OcrProviderUnavailableException` provides a safe failure type for future adapters.
- `backend/tests/OcrContractTest.php` covers supported request values, unsupported languages, Hausa/Arabic Unicode preservation, and invalid uncertainty offsets.

This is a contract and test foundation only. There is intentionally no vendor adapter, OCR endpoint, background job, OCR text persistence, or provider credential configured yet. The provider-selection gate above remains in force; do not enable external OCR until comparative Hausa/Arabic testing and privacy review are complete.
