# Stage A OCR benchmark protocol

Status: tooling and protocol prepared; real-corpus measurement is still pending.

## Purpose

Measure OCR quality on a fixed, permission-cleared corpus before Stage A is accepted. Synthetic unit tests prove that the adapter preserves particular strings and code-point offsets; they do not measure real-world OCR accuracy.

## Corpus handling

- Use only documents for which Kavoryn has permission to process and retain a benchmark copy.
- Prefer public, synthetic, or carefully redacted examples. Remove names, addresses, phone numbers, account numbers, signatures, QR/barcodes, and other personal data unless there is explicit authority and a documented need to retain them.
- Do not commit document images, PDFs, raw OCR output, or ground-truth text into this repository.
- Keep corpus files in an access-controlled local location; restrict access to the people performing the evaluation.
- Give each sample a random, non-identifying ID. Do not use customer IDs, filenames, or document numbers.
- Freeze a versioned manifest and record its SHA-256 separately from any private content before comparing providers or changing OCR configuration.
- Use independently checked ground truth. Have a second reviewer verify critical characters and fields; adjudicate disagreements before scoring.

## Required language coverage

Evaluate English, Hausa, Arabic, and French separately. Include mixed-language pages where available and retain a mixed-language label in the manifest. Hausa cases must explicitly include:

- `ɓ`, `ɗ`, `ƙ`, and `ƴ`
- Uppercase/lowercase variants where they occur
- Words where these characters appear at the beginning, middle, and end

Arabic must include right-to-left text, numerals, punctuation, and mixed Arabic/Latin lines. CER/WER measure text recognition; they do not prove Android right-to-left layout, visual reading order, or TalkBack behavior. Those require separate device tests.

## Metrics

The local benchmark utility reports micro-averaged, per-language:

- **CER** — Unicode code-point edit distance divided by the number of reference code points.
- **WER** — whitespace-token edit distance divided by the number of reference words.
- Sample counts and total reference units / edit counts.

The comparison is exact: case, punctuation, diacritics, and Unicode code points are not normalized. This is intentional for the Hausa special-character gate. CER/WER can exceed 1 when insertions are numerous.

The report contains aggregate metrics only; it does not include sample IDs, OCR text, or ground-truth text. Never add raw corpus data or per-document private excerpts to CI logs or PR comments.

## Input format

Use a local JSON Lines file with one object per sample:

```json
{"id":"synthetic-ha-001","language":"ha","reference":"Ƙarɓi ɗalibi","hypothesis":"Ƙarɓi ɗalibi"}
{"id":"synthetic-ar-001","language":"ar","reference":"مستند رسمي","hypothesis":"مستند رسمي"}
```

Run:

```sh
php backend/tools/ocr_corpus_benchmark.php /secure/local/path/ocr-corpus.jsonl
```

The utility validates UTF-8 and language codes, computes metrics offline, and writes aggregate JSON to stdout. Keep the corpus outside the repository. It does not call Google Vision, OpenAI, Gemini, or any network service.

## Critical-detail and uncertainty review

CER/WER alone are insufficient for documents where one digit or date can change the meaning. Separately annotate and review exact-match results for names, dates, amounts, phone numbers, reference numbers, deadlines, and other decision-critical fields. Do not place real values in public reports; report only aggregate pass/fail counts by language and field category.

Evaluate OCR uncertainty spans against manually annotated uncertain regions. Measure whether low-confidence words are correctly flagged and whether confident-but-wrong critical words are missed. The current adapter's 0.80 confidence threshold is a heuristic and must not be described as calibrated until this evaluation is complete.

## Release gate

Before Stage A OCR acceptance:

1. Approve and freeze a fixed corpus with a documented sample count and language breakdown.
2. Publish internal baseline CER/WER and critical-field exact-match rates per language.
3. Set target thresholds before reviewing final provider results; do not change thresholds after seeing scores without recording a new benchmark version.
4. Compare the selected OCR engine with at least one alternative under the same corpus and conditions.
5. Review representative failures manually, especially Hausa characters, Arabic reading order, tables, forms, small text, blur, and low-light scans.
6. Confirm uncertain spans, owner isolation, expiry, deletion, and original-text display separately.
7. Have TalkBack and low-vision users complete the document flow on actual Android devices.

A passing synthetic test, a passing CI run, or an API health response is not evidence that real-world OCR accuracy has passed this gate.
