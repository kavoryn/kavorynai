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

/** Pure policy helpers kept separate so language and input validation can be unit-tested. */
object SpeechTextPolicy {
    const val MAX_CHARS = 100_000
    const val MAX_UTTERANCE_CHARS = 3_500

    fun validate(text: String): String? = when {
        text.isBlank() -> "There is no document text to read."
        text.length > MAX_CHARS -> "This document is too long to read in one session."
        else -> null
    }

    /** Splits at word boundaries under Android's conservative per-utterance limit. */
    fun chunks(text: String): List<String> {
        require(validate(text) == null) { "Text is empty or exceeds the speech limit." }
        val result = mutableListOf<String>()
        var start = 0
        while (start < text.length) {
            var end = minOf(start + MAX_UTTERANCE_CHARS, text.length)
            if (end < text.length) {
                val boundary = text.lastIndexOf(' ', end)
                if (boundary > start) end = boundary
            }
            result += text.substring(start, end)
            start = end
            while (start < text.length && text[start].isWhitespace()) start++
        }
        return result
    }

    fun normalizeRate(value: Float): Float = value.coerceIn(0.5f, 1.5f)
}
