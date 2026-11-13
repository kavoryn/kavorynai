package com.kavoryn.app.ocr

import java.io.ByteArrayInputStream
import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

class HttpOcrSubmissionGatewayTest {
    @Test
    fun consentRequiresAuthenticatedSessionBeforeNetworkAccess() {
        val gateway = HttpOcrSubmissionGateway(
            baseUrl = "https://api.example.test",
            accessToken = " "
        )
        val request = OcrConsentRequest(
            language = "ha",
            payloadSha256 = "a".repeat(64)
        )

        val error = assertThrows(IllegalArgumentException::class.java) {
            kotlinx.coroutines.runBlocking { gateway.createConsent(request) }
        }
        assertEquals("A signed-in session is required.", error.message)
    }

    @Test
    fun consentRejectsNonHttpsApiBeforeNetworkAccess() {
        val gateway = HttpOcrSubmissionGateway(
            baseUrl = "http://api.example.test",
            accessToken = "user-access-token"
        )
        val request = OcrConsentRequest(
            language = "ha",
            payloadSha256 = "b".repeat(64)
        )

        val error = assertThrows(IllegalArgumentException::class.java) {
            kotlinx.coroutines.runBlocking { gateway.createConsent(request) }
        }
        assertEquals("The Kavoryn API must use HTTPS.", error.message)
    }

    @Test
    fun uploadRejectsUnsupportedMimeBeforeNetworkAccess() {
        val gateway = HttpOcrSubmissionGateway(
            baseUrl = "https://api.example.test",
            accessToken = "user-access-token"
        )

        assertThrows(IllegalArgumentException::class.java) {
            kotlinx.coroutines.runBlocking {
                gateway.uploadDocument(
                    stream = ByteArrayInputStream(byteArrayOf(1)),
                    mimeType = "text/plain",
                    language = "en",
                    consentToken = "one-time-token",
                    idempotencyKey = "123e4567-e89b-12d3-a456-426614174000"
                )
            }
        }
    }

    @Test
    fun uploadRequiresUuidIdempotencyKey() {
        val gateway = HttpOcrSubmissionGateway(
            baseUrl = "https://api.example.test",
            accessToken = "user-access-token"
        )

        val error = assertThrows(IllegalArgumentException::class.java) {
            kotlinx.coroutines.runBlocking {
                gateway.uploadDocument(
                    stream = ByteArrayInputStream(byteArrayOf(1)),
                    mimeType = "image/jpeg",
                    language = "en",
                    consentToken = "one-time-token",
                    idempotencyKey = "not-a-uuid"
                )
            }
        }
        assertEquals("A valid upload retry key is required.", error.message)
    }
}
