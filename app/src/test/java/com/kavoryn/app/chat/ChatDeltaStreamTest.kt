package com.kavoryn.app.chat

import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ChatDeltaStreamTest {
    @Test
    fun consumesQueuedDeltasInArrivalOrder() = runBlocking {
        val deltas = Channel<String>(Channel.UNLIMITED)
        val rendered = mutableListOf<String>()
        val consumer = launch {
            consumeChatDeltasInOrder(deltas) { rendered += it }
        }

        listOf("Sannu", ", ", "Kavoryn", " AI", " 👋").forEach { delta ->
            assertTrue(deltas.trySend(delta).isSuccess)
        }
        deltas.close()
        consumer.join()

        assertEquals(listOf("Sannu", ", ", "Kavoryn", " AI", " 👋"), rendered)
        assertEquals("Sannu, Kavoryn AI 👋", rendered.joinToString(separator = ""))
    }
}
