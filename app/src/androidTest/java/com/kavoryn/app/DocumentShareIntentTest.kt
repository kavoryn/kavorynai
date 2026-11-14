package com.kavoryn.app

import android.content.Intent
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class DocumentShareIntentTest {
    @Test
    fun shareIntentContainsOnlyTheUserSelectedPlainText() {
        val extractedText = "Hausa text: ɓ, ɗ, ƙ, ƴ"
        val intent = createDocumentShareIntent(extractedText)

        assertEquals(Intent.ACTION_SEND, intent.action)
        assertEquals("text/plain", intent.type)
        assertEquals(extractedText, intent.getStringExtra(Intent.EXTRA_TEXT))
        assertFalse(intent.hasExtra(Intent.EXTRA_STREAM))
    }

    @Test(expected = IllegalArgumentException::class)
    fun blankDocumentTextCannotBeShared() {
        createDocumentShareIntent("  ")
    }
}
