package com.kavoryn.app

import android.graphics.Rect

/** Product languages enabled for Stage A OCR evaluation. */
enum class OcrLanguage(val bcp47: String, val script: OcrScript) {
    ENGLISH("en", OcrScript.LATIN),
    HAUSA("ha", OcrScript.LATIN),
    ARABIC("ar", OcrScript.ARABIC),
    FRENCH("fr", OcrScript.LATIN)
}

enum class OcrScript { LATIN, ARABIC }

/** Offsets use Kotlin String UTF-16 indices. */
data class OcrUncertainSpan(val start: Int, val end: Int, val reason: OcrUncertaintyReason)

enum class OcrUncertaintyReason { LOW_CONFIDENCE, ILLEGIBLE, PARTIAL_WORD, LAYOUT_AMBIGUITY }

/** Null confidence means the provider did not supply a score. */
data class OcrWord(val text: String, val bounds: Rect?, val confidence: Float?) {
    init { require(confidence == null || confidence in 0f..1f) }
}

data class OcrResult(
    val text: String,
    val language: OcrLanguage,
    val words: List<OcrWord>,
    val uncertainSpans: List<OcrUncertainSpan>,
    val engineId: String
) {
    init {
        require(engineId.isNotBlank())
        require(uncertainSpans.all { it.start >= 0 && it.end > it.start && it.end <= text.length })
    }
}

sealed interface OcrFailure {
    data object UnsupportedLanguage : OcrFailure
    data object UnsupportedInput : OcrFailure
    data object NetworkUnavailable : OcrFailure
    data object ConsentRequired : OcrFailure
    data object ProviderUnavailable : OcrFailure
    data object EmptyResult : OcrFailure
    data class SafeMessage(val message: String) : OcrFailure
}

sealed interface OcrOutcome {
    data class Success(val result: OcrResult) : OcrOutcome
    data class Failure(val reason: OcrFailure) : OcrOutcome
}