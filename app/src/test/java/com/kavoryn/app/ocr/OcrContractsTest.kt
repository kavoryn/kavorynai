package com.kavoryn.app.ocr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class OcrContractsTest {
    @Test
    fun phaseOneLanguageCodesAreStableAndCaseInsensitive() {
        assertEquals(OcrLanguage.ENGLISH, OcrLanguage.fromCode("EN"))
        assertEquals(OcrLanguage.HAUSA, OcrLanguage.fromCode("ha"))
        assertEquals(OcrLanguage.ARABIC, OcrLanguage.fromCode("ar"))
        assertEquals(OcrLanguage.FRENCH, OcrLanguage.fromCode("fr"))
        assertEquals(null, OcrLanguage.fromCode("yo"))
    }

    @Test
    fun arabicIsMarkedRightToLeft() {
        assertEquals(ScriptDirection.RIGHT_TO_LEFT, OcrLanguage.ARABIC.scriptDirection)
        assertEquals(ScriptDirection.LEFT_TO_RIGHT, OcrLanguage.HAUSA.scriptDirection)
    }

    @Test
    fun hausaSpecialCharactersArePreserved() {
        val source = "ɓ ɗ ƙ ƴ"
        assertEquals(source, normalizeForSearch(source))
    }

    @Test
    fun searchNormalizationCollapsesWhitespaceWithoutRewritingLetters() {
        assertEquals("barka da zuwa", normalizeForSearch("  BARKA   DA\n ZUWA "))
        assertEquals("ɓ ɗ ƙ ƴ", normalizeForSearch(" ɓ   ɗ  ƙ ƴ "))
    }

    @Test
    fun validOcrResultPassesContractValidation() {
        val result = OcrDocumentResult(
            language = OcrLanguage.HAUSA,
            pages = listOf(
                OcrPageResult(
                    pageNumber = 1,
                    text = "Suna: Aisha",
                    words = listOf(OcrWord("Suna", 0.1f, 0.2f, 0.4f, 0.3f, 0.98f)),
                    uncertainSpans = listOf(OcrTextSpan(6, 11, "low_confidence"))
                )
            ),
            engineId = "test-engine"
        )
        assertTrue(validateOcrResult(result).isEmpty())
    }

    @Test
    fun uncertaintySpanOutsideSourceTextIsRejected() {
        val result = OcrDocumentResult(
            OcrLanguage.ENGLISH,
            listOf(OcrPageResult(1, "Hello", uncertainSpans = listOf(OcrTextSpan(2, 9, "low")))),
            "test"
        )
        assertTrue(validateOcrResult(result).any { it.contains("uncertainty span") })
    }

    @Test
    fun invalidConfidenceIsRejected() {
        val result = OcrDocumentResult(
            OcrLanguage.ENGLISH,
            listOf(OcrPageResult(1, "Hello", words = listOf(OcrWord("Hello", confidence = 1.5f)))),
            "test"
        )
        assertTrue(validateOcrResult(result).any { it.contains("confidence") })
    }

    @Test
    fun invertedGeometryIsRejected() {
        val result = OcrDocumentResult(
            OcrLanguage.ENGLISH,
            listOf(OcrPageResult(1, "Hello", words = listOf(OcrWord("Hello", 0.8f, 0.1f, 0.2f, 0.9f)))),
            "test"
        )
        assertTrue(validateOcrResult(result).any { it.contains("inverted") })
    }

    @Test
    fun documentTextJoinsPagesInPageOrder() {
        val result = OcrDocumentResult(
            OcrLanguage.ENGLISH,
            listOf(OcrPageResult(2, "Second"), OcrPageResult(1, "First")),
            "test"
        )
        assertEquals("First\n\nSecond", result.fullText)
    }


    @Test
    fun phaseOneLanguageSamplesKeepTheirOriginalUnicodeText() {
        val samples = mapOf(
            OcrLanguage.ENGLISH to "Please read this document carefully.",
            OcrLanguage.HAUSA to "Ƙarɓi: ɗalibi ya sayi ƙwai da ɓawon lemu.",
            OcrLanguage.ARABIC to "يرجى قراءة هذه الوثيقة بعناية",
            OcrLanguage.FRENCH to "Veuillez lire attentivement ce document."
        )
        samples.forEach { (language, text) ->
            val result = OcrDocumentResult(
                language,
                listOf(OcrPageResult(1, text)),
                "fixture"
            )
            assertEquals(text, result.fullText)
        }
    }

    @Test
    fun emptyDocumentResultIsRejected() {
        val result = OcrDocumentResult(OcrLanguage.ENGLISH, emptyList(), "test")
        assertFalse(validateOcrResult(result).isEmpty())
    }
}
