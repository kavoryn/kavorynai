package com.kavoryn.app.ocr

import java.io.InputStream
import java.util.Locale

/** Languages planned for Stage A. Availability does not imply OCR accuracy has been verified. */
enum class OcrLanguage(
    val code: String,
    val displayName: String,
    val scriptDirection: ScriptDirection
) {
    ENGLISH("en", "English", ScriptDirection.LEFT_TO_RIGHT),
    HAUSA("ha", "Hausa", ScriptDirection.LEFT_TO_RIGHT),
    ARABIC("ar", "العربية", ScriptDirection.RIGHT_TO_LEFT),
    FRENCH("fr", "Français", ScriptDirection.LEFT_TO_RIGHT);

    companion object {
        fun fromCode(code: String): OcrLanguage? =
            entries.firstOrNull { it.code == code.trim().lowercase() }
    }
}

enum class ScriptDirection { LEFT_TO_RIGHT, RIGHT_TO_LEFT }

enum class OcrVerificationStatus { NOT_VERIFIED, VERIFIED }

/**
 * Stream-based input avoids requiring the entire document to be loaded into RAM.
 * Implementations must close streams they open.
 */
data class OcrInput(
    val mimeType: String,
    val language: OcrLanguage,
    val openStream: () -> InputStream
)

data class OcrTextSpan(
    val start: Int,
    val endExclusive: Int,
    val reason: String
)

data class OcrWord(
    val text: String,
    /** Normalized 0..1 page coordinates; null when the engine cannot provide geometry. */
    val left: Float? = null,
    val top: Float? = null,
    val right: Float? = null,
    val bottom: Float? = null,
    val confidence: Float? = null
)

data class OcrPageResult(
    val pageNumber: Int,
    val text: String,
    val words: List<OcrWord> = emptyList(),
    /** Offsets are UTF-16 indices into this page's text. */
    val uncertainSpans: List<OcrTextSpan> = emptyList()
)

data class OcrDocumentResult(
    val language: OcrLanguage,
    val pages: List<OcrPageResult>,
    val engineId: String,
    val engineVersion: String? = null
) {
    val fullText: String
        get() = pages.sortedBy { it.pageNumber }.joinToString("\n\n") { it.text }
}

sealed interface OcrOutcome {
    data class Success(val result: OcrDocumentResult) : OcrOutcome
    data class Failure(val code: OcrFailureCode, val userMessage: String) : OcrOutcome
}

enum class OcrFailureCode {
    UNSUPPORTED_FORMAT,
    LANGUAGE_UNAVAILABLE,
    EMPTY_DOCUMENT,
    UNREADABLE_DOCUMENT,
    PROCESSING_UNAVAILABLE,
    CONSENT_REQUIRED,
    NETWORK_ERROR
}

/** Replaceable provider boundary. No vendor SDK is selected at this stage. */
interface OcrEngine {
    val engineId: String
    suspend fun recognize(input: OcrInput): OcrOutcome
}

/** Validates provider output before it is shown or passed to explanation services. */
fun validateOcrResult(result: OcrDocumentResult): List<String> {
    val issues = mutableListOf<String>()
    if (result.pages.isEmpty()) issues += "OCR result contains no pages."
    if (result.engineId.isBlank()) issues += "OCR engine identifier is missing."

    result.pages.forEachIndexed { index, page ->
        if (index == 0 && page.pageNumber != 1) issues += "The first page number must be 1."
        if (page.pageNumber < 1) issues += "Page numbers must be positive."
        if (index > 0 && page.pageNumber <= result.pages[index - 1].pageNumber) {
            issues += "Pages must be ordered and have unique page numbers."
        }
        page.uncertainSpans.forEach { span ->
            if (span.start < 0 || span.endExclusive <= span.start || span.endExclusive > page.text.length) {
                issues += "An OCR uncertainty span is outside the page text."
            }
        }
        page.words.forEach { word ->
            word.confidence?.let {
                if (!it.isFinite() || it !in 0f..1f) issues += "Word confidence must be between 0 and 1."
            }
            val coordinates = listOf(word.left, word.top, word.right, word.bottom)
            if (coordinates.any { it != null }) {
                if (coordinates.any { it == null || !it.isFinite() || it !in 0f..1f }) {
                    issues += "Word coordinates must all be normalized between 0 and 1."
                } else if (word.left!! > word.right!! || word.top!! > word.bottom!!) {
                    issues += "Word bounding box is inverted."
                }
            }
        }
    }
    return issues
}

/** Keep original OCR characters intact; normalization is only for matching/search. */
fun normalizeForSearch(text: String): String =
    text.trim().lowercase().replace(Regex("\\s+"), " ")
