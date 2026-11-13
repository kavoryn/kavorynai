package com.kavoryn.app

import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertSame
import org.junit.Assert.assertThrows
import org.junit.Test

class OcrResultPollingTest {
    private val completed = DocumentOcrResult(
        documentId = "123e4567-e89b-12d3-a456-426614174000",
        text = "Kavoryn OCR result",
        uncertainSpans = emptyList(),
        detectedLanguage = "en",
        requestId = null
    )

    @Test
    fun retriesNotReadyAndReturnsCompletedResult() = runBlocking {
        var attempts = 0
        val waits = mutableListOf<Long>()

        val result = awaitOcrResult(
            maxAttempts = 4,
            intervalMs = 25,
            fetch = {
                attempts++
                if (attempts < 3) throw DocumentOcrResultApiException(
                    404, "not_ready", "Still processing"
                )
                completed
            },
            wait = { waits += it }
        )

        assertEquals(completed, result)
        assertEquals(3, attempts)
        assertEquals(listOf(25L, 25L), waits)
    }

    @Test
    fun stopsAfterConfiguredNumberOfNotReadyResponses() = runBlocking {
        var attempts = 0
        val notReady = DocumentOcrResultApiException(404, "not_ready", "Still processing")

        val thrown = assertThrows(DocumentOcrResultApiException::class.java) {
            runBlocking {
                awaitOcrResult(
                    maxAttempts = 3,
                    intervalMs = 10,
                    fetch = {
                        attempts++
                        throw notReady
                    },
                    wait = { }
                )
            }
        }

        assertSame(notReady, thrown)
        assertEquals(3, attempts)
    }

    @Test
    fun doesNotRetryNon404Failures() = runBlocking {
        var attempts = 0
        val unauthorized = DocumentOcrResultApiException(401, "unauthorized", "Sign in again")

        val thrown = assertThrows(DocumentOcrResultApiException::class.java) {
            runBlocking {
                awaitOcrResult(
                    maxAttempts = 5,
                    fetch = {
                        attempts++
                        throw unauthorized
                    },
                    wait = { }
                )
            }
        }

        assertSame(unauthorized, thrown)
        assertEquals(1, attempts)
    }

    @Test
    fun rejectsInvalidPollingBounds() = runBlocking {
        assertThrows(IllegalArgumentException::class.java) {
            runBlocking {
                awaitOcrResult(maxAttempts = 0, fetch = { completed })
            }
        }
        assertThrows(IllegalArgumentException::class.java) {
            runBlocking {
                awaitOcrResult(intervalMs = -1, fetch = { completed })
            }
        }
    }
}
