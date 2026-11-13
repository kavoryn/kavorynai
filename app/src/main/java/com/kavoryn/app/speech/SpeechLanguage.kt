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
        fun fromContentLanguage(value: String): SpeechLanguage? =
            entries.firstOrNull { it.tag.equals(value.trim(), ignoreCase = true) }
    }
}

/** Pure policy helpers kept separate so language and input validation can be unit-tested. */
object SpeechTextPolicy {
    const val MAX_CHARS = 100_000

    fun validate(text: String): String? = when {
        text.isBlank() -> "There is no document text to read."
        text.length > MAX_CHARS -> "This document is too long to read in one session."
        else -> null
    }

    fun normalizeRate(value: Float): Float = value.coerceIn(0.5f, 1.5f)
}
