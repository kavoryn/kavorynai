package com.kavoryn.app.qa

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class DocumentQuestionApiTest {
    @Test
    fun parsesGroundedAnswerAndExcerpts() {
        val parsed = parseDocumentQuestionAnswer(
            """{"answer":"The deadline is 30 June.","source_excerpts":["Payment is due by 30 June."],"insufficient_information":false}"""
        )
        assertEquals("The deadline is 30 June.", parsed.answer)
        assertEquals(listOf("Payment is due by 30 June."), parsed.sourceExcerpts)
        assertFalse(parsed.insufficientInformation)
    }

    @Test
    fun parsesInsufficientInformationWithoutInventingEvidence() {
        val parsed = parseDocumentQuestionAnswer(
            """{"answer":"The document does not provide enough information.","source_excerpts":[],"insufficient_information":true}"""
        )
        assertTrue(parsed.insufficientInformation)
        assertTrue(parsed.sourceExcerpts.isEmpty())
    }

    @Test
    fun rejectsAnswerForAnotherDocument() {
        val documentId = "a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12"
        val body = """{"document_id":"b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12","answer":"Answer","source_excerpts":[]}"""
        try {
            parseDocumentQuestionAnswer(body, documentId)
            throw AssertionError("A response for another document must be rejected.")
        } catch (_: IllegalArgumentException) {
            // Expected: response document ID must match the requested document.
        }
    }

    @Test(expected = IllegalArgumentException::class)
    fun rejectsEmptyAnswer() {
        parseDocumentQuestionAnswer("""{"answer":" ","source_excerpts":[]}""")
    }

    @Test(expected = IllegalArgumentException::class)
    fun rejectsTooManyExcerpts() {
        parseDocumentQuestionAnswer(
            """{"answer":"Answer","source_excerpts":["1","2","3","4","5","6"]}"""
        )
    }

    @Test(expected = IllegalArgumentException::class)
    fun rejectsInsecureApiUrl() {
        validatedApiRoot("http://example.test")
    }

    @Test
    fun acceptsHttpsApiOrigin() {
        assertEquals("https://api.example.test", validatedApiRoot("https://api.example.test/"))
    }
}
