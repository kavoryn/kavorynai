package com.kavoryn.app

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class DocumentSharePolicyTest {
    @Test
    fun rejectsBlankText() {
        assertTrue(DocumentSharePolicy.validationError("  ") != null)
    }

    @Test
    fun preservesHausaArabicAndMixedTextExactly() {
        val source = "Suna: Aisha — ɓ ɗ ƙ ƴ — مرحباً — NGN 45,000"
        assertNull(DocumentSharePolicy.validationError(source))
        assertEquals(source, DocumentSharePolicy.textForSharing(source))
    }

    @Test
    fun refusesOverlongTextInsteadOfTruncatingIt() {
        val source = "x".repeat(DocumentSharePolicy.MAX_SHARE_TEXT_CHARS + 1)
        assertTrue(DocumentSharePolicy.validationError(source) != null)
    }

    @Test
    fun keepsBoundaryLengthTextIntact() {
        val source = "k".repeat(DocumentSharePolicy.MAX_SHARE_TEXT_CHARS)
        assertNull(DocumentSharePolicy.validationError(source))
        assertEquals(source, DocumentSharePolicy.textForSharing(source))
    }
}
