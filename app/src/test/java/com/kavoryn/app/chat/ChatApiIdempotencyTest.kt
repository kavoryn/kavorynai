package com.kavoryn.app.chat

import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

class ChatApiIdempotencyTest {
    @Test
    fun messagePayloadCarriesStableClientTurnId() {
        val turnId = "a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12"
        val payload = buildChatMessagePayload(turnId, "Sannu Kavoryn")

        assertEquals(turnId, payload.getString("client_turn_id"))
        assertEquals("Sannu Kavoryn", payload.getString("message"))
    }

    @Test
    fun payloadRejectsInvalidClientTurnId() {
        assertThrows(IllegalArgumentException::class.java) {
            buildChatMessagePayload("not-a-uuid", "Sannu")
        }
    }

    @Test
    fun payloadRejectsBlankMessage() {
        assertThrows(IllegalArgumentException::class.java) {
            buildChatMessagePayload("a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12", "  ")
        }
    }
}
