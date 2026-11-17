package com.kavoryn.app.speech

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class SpeechTextPolicyTest {
    @Test fun mapsSupportedLanguageTags() {
        assertEquals(SpeechLanguage.HAUSA, SpeechLanguage.fromContentLanguage("ha"))
        assertEquals(SpeechLanguage.HAUSA, SpeechLanguage.fromContentLanguage("ha-NG"))
        assertEquals(SpeechLanguage.ARABIC, SpeechLanguage.fromContentLanguage("ar-SA"))
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
        assertEquals(source, SpeechTextPolicy.chunks(source).joinToString(""))
    }

    @Test fun longTextIsChunkedWithoutLosingCharacters() {
        val source = (1..900).joinToString(" ") { "document" }
        val chunks = SpeechTextPolicy.chunks(source)
        assertTrue(chunks.all { it.length <= SpeechTextPolicy.MAX_UTTERANCE_CHARS })
        assertEquals(
            source.replace(Regex("\\s+"), " "),
            chunks.joinToString(" ").replace(Regex("\\s+"), " ")
        )
    }

    @Test fun chunkRangesRetainOffsetsIntoTheOriginalText() {
        val source = (1..900).joinToString(" ") { "document" }
        val chunks = SpeechTextPolicy.chunkRanges(source)
        assertTrue(chunks.isNotEmpty())
        chunks.forEach { chunk ->
            assertEquals(source.substring(chunk.startIndex, chunk.endExclusive), chunk.text)
            assertTrue(chunk.endExclusive > chunk.startIndex)
            assertTrue(chunk.text.length <= SpeechTextPolicy.MAX_UTTERANCE_CHARS)
        }
        chunks.zipWithNext().forEach { (first, second) ->
            assertTrue(first.endExclusive <= second.startIndex)
            assertTrue(source.substring(first.endExclusive, second.startIndex).all { it.isWhitespace() })
        }
    }

    @Test fun longTextSplitsAtNewlineAndTabBoundaries() {
        val source = (1..900).joinToString("\n\t") { "Ƙarɓi" }
        val chunks = SpeechTextPolicy.chunkRanges(source)

        assertTrue(chunks.size > 1)
        assertTrue(chunks.all { it.text.length <= SpeechTextPolicy.MAX_UTTERANCE_CHARS })
        chunks.dropLast(1).forEach { chunk ->
            assertTrue(
                "Each non-final chunk must end immediately before a whitespace boundary.",
                source[chunk.endExclusive].isWhitespace()
            )
        }
        assertEquals(
            source.filterNot { it.isWhitespace() },
            chunks.joinToString("") { it.text }.filterNot { it.isWhitespace() }
        )
    }

    @Test fun resumeChunkingStartsAtRequestedTextOffset() {
        val source = "First paragraph.  Second paragraph starts here."
        val offset = source.indexOf("Second")
        val chunks = SpeechTextPolicy.chunkRanges(source, offset)
        assertEquals(offset, chunks.first().startIndex)
        assertTrue(chunks.first().text.startsWith("Second"))
    }

    @Test fun rateIsBoundedAndNonFiniteValuesUseNormalSpeed() {
        assertEquals(0.5f, SpeechTextPolicy.normalizeRate(0.1f))
        assertEquals(1.5f, SpeechTextPolicy.normalizeRate(2f))
        assertEquals(1f, SpeechTextPolicy.normalizeRate(1f))
        assertEquals(1f, SpeechTextPolicy.normalizeRate(Float.NaN))
        assertEquals(1f, SpeechTextPolicy.normalizeRate(Float.POSITIVE_INFINITY))
    }
}
