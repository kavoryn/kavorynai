package com.kavoryn.app.speech

import java.util.Locale

/** Maps Kavoryn's supported content languages to Android TTS locale requests. */
enum class SpeechLanguage(val tag: String) {
    ENGLISH("en"),
    HAUSA("ha"),
    ARABIC("ar"),
    FRENCH("fr");

    fun toLocale(): Locale = Locale.forLanguageTag(tag)

    companion object {
        fun fromContentLanguage(value: String): SpeechLanguage? {
            val primaryTag = value.trim().replace("_", "-").substringBefore("-")
            if (primaryTag.isBlank()) return null
            return entries.firstOrNull { it.tag.equals(primaryTag, ignoreCase = true) }
        }
    }
}

data class SpeechChunk(
    val text: String,
    val startIndex: Int,
    val endExclusive: Int
)

 /** Pure policy helpers kept separate so language and input validation can be unit-tested. */
object SpeechTextPolicy {
    const val MAX_CHARS = 100_000
    const val MAX_UTTERANCE_CHARS = 3_500

    fun validate(text: String): String? = when {
        text.isBlank() -> "There is no document text to read."
        text.length > MAX_CHARS -> "This document is too long to read in one session."
        else -> null
    }

    /** Splits text at safe word boundaries and retains offsets into the original string. */
    fun chunkRanges(text: String, startIndex: Int = 0): List<SpeechChunk> {
        require(validate(text) == null) { "Text is empty or exceeds the speech limit." }
        require(startIndex in 0..text.length) { "Speech resume offset is outside the text." }

        val result = mutableListOf<SpeechChunk>()
        var start = startIndex
        while (start < text.length && text[start].isWhitespace()) start++
        while (start < text.length) {
            var end = minOf(start + MAX_UTTERANCE_CHARS, text.length)
            if (end < text.length) {
                // OCR text can contain paragraph breaks, tabs and other whitespace.
                // Split at the last whitespace boundary, not only an ASCII space.
                var boundary = -1
                for (index in end downTo (start + 1)) {
                    if (text[index].isWhitespace()) {
                        boundary = index
                        break
                    }
                }
                if (boundary > start) end = boundary
                else if (end > start &&
                    Character.isHighSurrogate(text[end - 1]) &&
                    Character.isLowSurrogate(text[end])
                ) {
                    end--
                }
            }
            result += SpeechChunk(
                text = text.substring(start, end),
                startIndex = start,
                endExclusive = end
            )
            start = end
            while (start < text.length && text[start].isWhitespace()) start++
        }
        return result
    }

    /** Kept for callers that only need utterance text. */
    fun chunks(text: String): List<String> = chunkRanges(text).map { it.text }

    fun normalizeRate(value: Float): Float =
        if (value.isFinite()) value.coerceIn(0.5f, 1.5f) else 1f
}
