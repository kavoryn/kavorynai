package com.kavoryn.app.ocr

import java.io.ByteArrayInputStream
import java.io.InputStream
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class OcrSubmissionTest {
    private class FakeGateway(
        private val grant: OcrConsentGrant? = OcrConsentGrant("short-lived-token")
    ) : OcrSubmissionGateway {
        var consentCalls = 0
        var uploadCalls = 0
        var consentRequest: OcrConsentRequest? = null
        var uploadedBytes: ByteArray? = null
        var uploadedLanguage: String? = null
        var uploadedToken: String? = null
        var uploadedIdempotencyKey: String? = null

        override suspend fun createConsent(request: OcrConsentRequest): OcrConsentGrant? {
            consentCalls++
            consentRequest = request
            return grant
        }

        override suspend fun uploadDocument(
            stream: InputStream,
            mimeType: String,
            language: String,
            consentToken: String,
            idempotencyKey: String
        ): OcrJobReceipt {
            uploadCalls++
            uploadedBytes = stream.readBytes()
            uploadedLanguage = language
            uploadedToken = consentToken
            uploadedIdempotencyKey = idempotencyKey
            return OcrJobReceipt("doc-1", "job-1", "queued")
        }
    }

    @Test
    fun consentIsRequiredBeforeAnyGatewayCall() = runBlocking {
        val gateway = FakeGateway()
        val coordinator = OcrSubmissionCoordinator(gateway)
        val result = coordinator.submit(
            OcrInput("image/jpeg", OcrLanguage.HAUSA) { ByteArrayInputStream(byteArrayOf(1, 2, 3)) },
            userConfirmedExternalProcessing = false
        )

        assertTrue(result is OcrSubmissionOutcome.Rejected)
        assertEquals(OcrFailureCode.CONSENT_REQUIRED, (result as OcrSubmissionOutcome.Rejected).code)
        assertEquals(0, gateway.consentCalls)
        assertEquals(0, gateway.uploadCalls)
    }

    @Test
    fun consentDigestAndUploadAreBoundToSameInput() = runBlocking {
        val bytes = "hello".toByteArray()
        val gateway = FakeGateway()
        val coordinator = OcrSubmissionCoordinator(gateway)
        val result = coordinator.submit(
            OcrInput("application/pdf", OcrLanguage.HAUSA) { ByteArrayInputStream(bytes) },
            userConfirmedExternalProcessing = true,
            idempotencyKey = "retry-stable-key"
        )

        assertTrue(result is OcrSubmissionOutcome.Submitted)
        assertEquals("2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824",
            gateway.consentRequest?.payloadSha256)
        assertEquals("ha", gateway.consentRequest?.language)
        assertEquals(bytes.toList(), gateway.uploadedBytes?.toList())
        assertEquals("ha", gateway.uploadedLanguage)
        assertEquals("short-lived-token", gateway.uploadedToken)
        assertEquals("retry-stable-key", gateway.uploadedIdempotencyKey)
        assertEquals(1, gateway.consentCalls)
        assertEquals(1, gateway.uploadCalls)
    }

    @Test
    fun unsupportedMimeIsRejectedWithoutGatewayCall() = runBlocking {
        val gateway = FakeGateway()
        val result = OcrSubmissionCoordinator(gateway).submit(
            OcrInput("text/plain", OcrLanguage.ENGLISH) { ByteArrayInputStream("hi".toByteArray()) },
            userConfirmedExternalProcessing = true
        )
        assertTrue(result is OcrSubmissionOutcome.Rejected)
        assertEquals(OcrFailureCode.UNSUPPORTED_FORMAT, (result as OcrSubmissionOutcome.Rejected).code)
        assertEquals(0, gateway.consentCalls)
    }

    @Test
    fun emptyDocumentIsRejectedBeforeConsent() = runBlocking {
        val gateway = FakeGateway()
        val result = OcrSubmissionCoordinator(gateway).submit(
            OcrInput("image/png", OcrLanguage.ARABIC) { ByteArrayInputStream(byteArrayOf()) },
            userConfirmedExternalProcessing = true
        )
        assertTrue(result is OcrSubmissionOutcome.Rejected)
        assertEquals(OcrFailureCode.EMPTY_DOCUMENT, (result as OcrSubmissionOutcome.Rejected).code)
        assertEquals(0, gateway.consentCalls)
        assertFalse(gateway.uploadCalls > 0)
    }


    @Test
    fun blankConsentTokenPreventsUpload() = runBlocking {
        val gateway = FakeGateway(grant = OcrConsentGrant("  "))
        val result = OcrSubmissionCoordinator(gateway).submit(
            OcrInput("image/jpeg", OcrLanguage.ENGLISH) { ByteArrayInputStream(byteArrayOf(1)) },
            userConfirmedExternalProcessing = true
        )
        assertTrue(result is OcrSubmissionOutcome.Rejected)
        assertEquals(0, gateway.uploadCalls)
    }

    @Test
    fun blankIdempotencyKeyIsRejectedBeforeNetworkCall() = runBlocking {
        val gateway = FakeGateway()
        val result = OcrSubmissionCoordinator(gateway).submit(
            OcrInput("image/jpeg", OcrLanguage.ENGLISH) { ByteArrayInputStream(byteArrayOf(1)) },
            userConfirmedExternalProcessing = true,
            idempotencyKey = " "
        )
        assertTrue(result is OcrSubmissionOutcome.Rejected)
        assertEquals(0, gateway.consentCalls)
        assertEquals(0, gateway.uploadCalls)
    }

    @Test
    fun coroutineCancellationIsPropagated() = runBlocking {
        val gateway = object : OcrSubmissionGateway {
            override suspend fun createConsent(request: OcrConsentRequest): OcrConsentGrant? {
                throw CancellationException("cancelled")
            }
            override suspend fun uploadDocument(
                stream: InputStream,
                mimeType: String,
                language: String,
                consentToken: String,
                idempotencyKey: String
            ): OcrJobReceipt = error("Upload must not run after cancellation")
        }
        var cancellationPropagated = false
        try {
            OcrSubmissionCoordinator(gateway).submit(
                OcrInput("image/png", OcrLanguage.ARABIC) { ByteArrayInputStream(byteArrayOf(1)) },
                userConfirmedExternalProcessing = true
            )
        } catch (_: CancellationException) {
            cancellationPropagated = true
        }
        assertTrue(cancellationPropagated)
    }

    @Test
    fun missingConsentGrantPreventsUpload() = runBlocking {
        val gateway = FakeGateway(grant = null)
        val result = OcrSubmissionCoordinator(gateway).submit(
            OcrInput("image/webp", OcrLanguage.FRENCH) { ByteArrayInputStream(byteArrayOf(4, 5)) },
            userConfirmedExternalProcessing = true
        )
        assertTrue(result is OcrSubmissionOutcome.Rejected)
        assertEquals(OcrFailureCode.CONSENT_REQUIRED, (result as OcrSubmissionOutcome.Rejected).code)
        assertEquals(1, gateway.consentCalls)
        assertEquals(0, gateway.uploadCalls)
    }
}
