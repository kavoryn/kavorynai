package com.kavoryn.app

import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

class DocumentOcrResultApiTest {
    @Test
    fun convertsCodePointOffsetsToUtf16ForEmojiAndArabicText() {
        val text = "A😀بC"
        // Code points: A(0), emoji(1), Arabic letter(2), C(3).
        // UTF-16 uses two code units for the emoji.
        assertEquals(1 to 4, codePointRangeToUtf16(text, 1, 3))
    }

    @Test
    fun rejectsNegativeOrEmptyRanges() {
        assertThrows(IllegalArgumentException::class.java) {
            codePointRangeToUtf16("hello", -1, 2)
        }
        assertThrows(IllegalArgumentException::class.java) {
            codePointRangeToUtf16("hello", 2, 2)
        }
    }

    @Test
    fun rejectsRangesPastTheEndOfText() {
        assertThrows(IllegalArgumentException::class.java) {
            codePointRangeToUtf16("abc", 1, 4)
        }
    }
}
