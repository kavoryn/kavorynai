package com.kavoryn.app.chat

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test

class ChatSseEventDecoderTest {
    private val decoder = ChatSseEventDecoder()

    @Test
    fun decodesTextDeltaWithoutMarkingStreamComplete() {
        val received = StringBuilder()

        val completed = decoder.decode("""{"type":"delta","text":"Akwai lafiya"}""") {
            received.append(it)
        }

        assertFalse(completed)
        assertEquals("Akwai lafiya", received.toString())
    }

    @Test
    fun decodesUnicodeAndMultilineAssistantText() {
        val received = StringBuilder()

        val completed = decoder.decode("""{"type":"delta","text":"Sannu, Hausa! \nLafiya lau?"}""") {
            received.append(it)
        }

        assertFalse(completed)
        assertEquals("Sannu, Hausa! \nLafiya lau?", received.toString())
    }

    @Test
    fun providesSafeFallbackWhenErrorEventHasNoMessage() {
        val error = assertThrows(ChatApiException::class.java) {
            decoder.decode("""{"type":"error","code":"provider_error"}""") {}
        }

        assertEquals("provider_error", error.code)
        assertEquals("The AI response could not be completed.", error.message)
    }

    @Test
    fun recognizesTerminalCompletionEvent() {
        assertTrue(decoder.decode("""{"type":"completed","message_id":"message-1"}""") {})
    }

    @Test
    fun surfacesProviderSafeErrorCodeAndMessage() {
        val error = assertThrows(ChatApiException::class.java) {
            decoder.decode("""{"type":"error","code":"chat_rate_limited","message":"Wait a moment."}""") {}
        }

        assertEquals("chat_rate_limited", error.code)
        assertEquals("Wait a moment.", error.message)
    }

    @Test
    fun rejectsMalformedEventJson() {
        val error = assertThrows(ChatApiException::class.java) {
            decoder.decode("""{"type":""") {}
        }

        assertEquals("invalid_stream", error.code)
    }

    @Test
    fun rejectsOversizedEvent() {
        val tooLarge = "x".repeat(ChatSseEventDecoder.MAX_EVENT_CHARS + 1)

        val error = assertThrows(ChatApiException::class.java) {
            decoder.decode(tooLarge) {}
        }

        assertEquals("event_too_large", error.code)
    }

    @Test
    fun ignoresUnknownEventTypes() {
        val completed = decoder.decode("""{"type":"heartbeat"}""") {}

        assertFalse(completed)
    }
}
