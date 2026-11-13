package com.kavoryn.app.ocr

import java.io.InputStream
import java.security.MessageDigest
import java.util.UUID
import kotlinx.coroutines.CancellationException

data class OcrConsentRequest(
    val action: String = "document.process",
    val language: String,
    val payloadSha256: String,
    val confirmed: Boolean = true
)

data class OcrConsentGrant(
    /** Opaque, short-lived token issued by the authenticated Kavoryn API. */
    val token: String
)

data class OcrJobReceipt(
    val documentId: String,
    /** Internal queue IDs are intentionally not exposed by the API. */
    val jobId: String?,
    val status: String
)

/**
 * Implement this with the authenticated Kavoryn API. Implementations must use HTTPS,
 * must not log file bytes/tokens, and must never persist document content by default.
 */
interface OcrSubmissionGateway {
    suspend fun createConsent(request: OcrConsentRequest): OcrConsentGrant?
    suspend fun uploadDocument(
        stream: InputStream,
        mimeType: String,
        language: String,
        consentToken: String,
        idempotencyKey: String
    ): OcrJobReceipt
}

sealed interface OcrSubmissionOutcome {
    data class Submitted(val receipt: OcrJobReceipt) : OcrSubmissionOutcome
    data class Rejected(val code: OcrFailureCode, val message: String) : OcrSubmissionOutcome
}

/**
 * Orchestrates the consent-before-upload sequence. The gateway is deliberately an
 * interface: no provider, endpoint, API key, or network implementation is hard-coded.
 */
class OcrSubmissionCoordinator(
    private val gateway: OcrSubmissionGateway
) {
    suspend fun submit(
        input: OcrInput,
        userConfirmedExternalProcessing: Boolean,
        idempotencyKey: String = UUID.randomUUID().toString()
    ): OcrSubmissionOutcome {
        if (!userConfirmedExternalProcessing) {
            return OcrSubmissionOutcome.Rejected(
                OcrFailureCode.CONSENT_REQUIRED,
                "Please review and confirm document processing before uploading."
            )
        }
        if (!isSupportedDocumentMime(input.mimeType)) {
            return OcrSubmissionOutcome.Rejected(
                OcrFailureCode.UNSUPPORTED_FORMAT,
                "Choose a PDF, JPEG, PNG or WebP document."
            )
        }
        if (idempotencyKey.isBlank()) {
            return OcrSubmissionOutcome.Rejected(
                OcrFailureCode.PROCESSING_UNAVAILABLE,
                "The request could not be prepared. Please try again."
            )
        }

        return try {
            val digest = sha256(input.openStream)
            val grant = gateway.createConsent(
                OcrConsentRequest(
                    language = input.language.code,
                    payloadSha256 = digest
                )
            ) ?: return OcrSubmissionOutcome.Rejected(
                OcrFailureCode.CONSENT_REQUIRED,
                "Your processing permission could not be confirmed. Please review again."
            )

            if (grant.token.isBlank()) {
                return OcrSubmissionOutcome.Rejected(
                    OcrFailureCode.CONSENT_REQUIRED,
                    "Your processing permission could not be confirmed. Please review again."
                )
            }

            val receipt = input.openStream().use { stream ->
                gateway.uploadDocument(
                    stream = stream,
                    mimeType = input.mimeType,
                    language = input.language.code,
                    consentToken = grant.token,
                    idempotencyKey = idempotencyKey
                )
            }
            OcrSubmissionOutcome.Submitted(receipt)
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (_: UnsupportedDocumentException) {
            OcrSubmissionOutcome.Rejected(
                OcrFailureCode.EMPTY_DOCUMENT,
                "The selected document is empty or cannot be read."
            )
        } catch (_: Exception) {
            // Do not expose provider exceptions, tokens, paths, or document details.
            OcrSubmissionOutcome.Rejected(
                OcrFailureCode.NETWORK_ERROR,
                "Document processing could not be started. Check your connection and try again."
            )
        }
    }
}

private fun isSupportedDocumentMime(mimeType: String): Boolean =
    mimeType.equals("application/pdf", ignoreCase = true) ||
        mimeType.equals("image/jpeg", ignoreCase = true) ||
        mimeType.equals("image/png", ignoreCase = true) ||
        mimeType.equals("image/webp", ignoreCase = true)

private class UnsupportedDocumentException : Exception()

/** Streams SHA-256 without loading the whole document into memory. */
internal fun sha256(openStream: () -> InputStream): String {
    val digest = MessageDigest.getInstance("SHA-256")
    var bytesRead = 0L
    openStream().use { stream ->
        val buffer = ByteArray(DEFAULT_BUFFER_SIZE)
        while (true) {
            val count = stream.read(buffer)
            if (count < 0) break
            if (count == 0) continue
            bytesRead += count
            digest.update(buffer, 0, count)
        }
    }
    if (bytesRead == 0L) throw UnsupportedDocumentException()
    return digest.digest().joinToString("") { "%02x".format(it.toInt() and 0xff) }
}
