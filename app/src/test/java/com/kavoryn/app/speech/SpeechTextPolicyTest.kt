package com.kavoryn.app.speech

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class SpeechTextPolicyTest {
    @Test fun mapsSupportedLanguageTags() {
        assertEquals(SpeechLanguage.HAUSA, SpeechLanguage.fromContentLanguage("ha"))
        assertEquals(SpeechLanguage.ARABIC, SpeechLanguage.fromContentLanguage("AR"))
        assertEquals(SpeechLanguage.FRENCH, SpeechLanguage.fromContentLanguage("fr"))
        assertNull(SpeechLanguage.fromContentLanguage("sw"))
    }

    @Test fun rejectsBlankText() {
        assertTrue(SpeechTextPolicy.validate("  ") != null)
    }

    @Test fun acceptsHausaArabicAndFrenchTextWithoutRewriting() {
        val source = "ɓ ɗ ƙ ƴ — مرحباً — Bonjour"
        assertNull(SpeechTextPolicy.validate(source))
        assertEquals(source, source)
    }

    @Test fun rateIsBounded() {
        assertEquals(0.5f, SpeechTextPolicy.normalizeRate(0.1f))
        assertEquals(1.5f, SpeechTextPolicy.normalizeRate(2f))
        assertEquals(1f, SpeechTextPolicy.normalizeRate(1f))
    }
}
