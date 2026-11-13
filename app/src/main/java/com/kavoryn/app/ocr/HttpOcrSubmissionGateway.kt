package com.kavoryn.app.ocr

import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.io.InputStream
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID

/**
 * HTTPS implementation of the authenticated OCR consent + upload API.
 * The caller supplies the current Supabase user's access token. Server credentials
 * and AI/OCR provider keys are never accepted by this class.
 */
class HttpOcrSubmissionGateway(
    private val baseUrl: String,
    private val accessToken: String,
    private val connectTimeoutMs: Int = 15_000,
    private val readTimeoutMs: Int = 60_000
) : OcrSubmissionGateway {

    override suspend fun createConsent(request: OcrConsentRequest): OcrConsentGrant? {
        require(request.confirmed) { "Explicit processing consent is required." }
        require(SHA256_REGEX.matches(request.payloadSha256)) { "A valid document fingerprint is required." }
        require(accessToken.isNotBlank()) { "A signed-in session is required." }

        val body = JSONObject()
            .put("action", request.action)
            .put("payload_sha256", request.payloadSha256)
            .put("language", request.language)
            .put("translate", false)
            .put("confirmed", true)
            .toString()
            .toByteArray(Charsets.UTF_8)

        val response = execute("/v1/consents", "POST", "application/json; charset=utf-8", body)
        if (response.status != HttpURLConnection.HTTP_CREATED) return null
        val token = JSONObject(response.body).optString("consent_token")
        return token.takeIf { it.isNotBlank() }?.let(::OcrConsentGrant)
    }

    override suspend fun uploadDocument(
        stream: InputStream,
        mimeType: String,
        language: String,
        consentToken: String,
        idempotencyKey: String
    ): OcrJobReceipt {
        require(accessToken.isNotBlank()) { "A signed-in session is required." }
        require(consentToken.isNotBlank()) { "A valid processing consent is required." }
        require(UUID_REGEX.matches(idempotencyKey)) { "A valid upload retry key is required." }
        val extension = extensionFor(mimeType)
        val boundary = "Kavoryn" + UUID.randomUUID().toString().replace("-", "")
        val connection = openConnection("/v1/documents").apply {
            requestMethod = "POST"
            doOutput = true
            setChunkedStreamingMode(64 * 1024)
            setRequestProperty("Content-Type", "multipart/form-data; boundary=$boundary")
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Cache-Control", "no-store")
            setRequestProperty("Idempotency-Key", idempotencyKey)
        }

        try {
            connection.outputStream.use { output ->
                fun field(name: String, value: String) {
                    output.write("--$boundary\r\n".toByteArray(Charsets.UTF_8))
                    output.write("Content-Disposition: form-data; name=\"$name\"\r\n\r\n".toByteArray(Charsets.UTF_8))
                    output.write(value.toByteArray(Charsets.UTF_8))
                    output.write("\r\n".toByteArray(Charsets.UTF_8))
                }
                field("consent_token", consentToken)
                field("output_language", language)
                field("translate", "false")
                output.write("--$boundary\r\n".toByteArray(Charsets.UTF_8))
                output.write("Content-Disposition: form-data; name=\"file\"; filename=\"document.$extension\"\r\n".toByteArray(Charsets.UTF_8))
                output.write("Content-Type: $mimeType\r\n\r\n".toByteArray(Charsets.UTF_8))
                stream.use { input ->
                    val buffer = ByteArray(DEFAULT_BUFFER_SIZE)
                    while (true) {
                        val count = input.read(buffer)
                        if (count < 0) break
                        if (count > 0) output.write(buffer, 0, count)
                    }
                }
                output.write("\r\n--$boundary--\r\n".toByteArray(Charsets.UTF_8))
            }

            val response = readResponse(connection)
            if (response.status != HttpURLConnection.HTTP_CREATED) {
                throw OcrGatewayException(
                    response.status, errorCode(response.body),
                    "The secure document upload could not be completed."
                )
            }
            val json = JSONObject(response.body)
            val documentId = json.getString("document_id")
            require(UUID_REGEX.matches(documentId)) { "The server returned an invalid document reference." }
            return OcrJobReceipt(documentId, null, json.getString("status"))
        } catch (error: OcrGatewayException) {
            throw error
        } catch (_: Exception) {
            throw OcrGatewayException(
                null, null,
                "The secure document upload failed. Check your connection and try again."
            )
        } finally {
            connection.disconnect()
        }
    }

    private fun execute(path: String, method: String, contentType: String, body: ByteArray): GatewayResponse {
        val connection = openConnection(path).apply {
            requestMethod = method
            doOutput = true
            setRequestProperty("Content-Type", contentType)
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Cache-Control", "no-store")
        }
        try {
            connection.outputStream.use { it.write(body) }
            return readResponse(connection)
        } finally {
            connection.disconnect()
        }
    }

    private fun openConnection(path: String): HttpURLConnection {
        val root = baseUrl.trimEnd('/')
        require(root.startsWith("https://", ignoreCase = true)) { "The Kavoryn API must use HTTPS." }
        return (URL("$root$path").openConnection() as HttpURLConnection).apply {
            connectTimeout = connectTimeoutMs
            readTimeout = readTimeoutMs
            setRequestProperty("Authorization", "Bearer $accessToken")
        }
    }

    private fun readResponse(connection: HttpURLConnection): GatewayResponse {
        val status = connection.responseCode
        val input = if (status in 200..299) connection.inputStream else connection.errorStream
            ?: throw OcrGatewayException(status, null, "The Kavoryn API returned no response.")
        val bytes = input.use { source ->
            val output = ByteArrayOutputStream()
            val buffer = ByteArray(8192)
            var total = 0
            while (true) {
                val count = source.read(buffer)
                if (count < 0) break
                total += count
                if (total > MAX_RESPONSE_BYTES) {
                    throw OcrGatewayException(status, null, "The Kavoryn API response was too large.")
                }
                output.write(buffer, 0, count)
            }
            output.toByteArray()
        }
        return GatewayResponse(status, bytes.toString(Charsets.UTF_8))
    }

    private fun errorCode(body: String): String? = try {
        JSONObject(body).optJSONObject("error")?.optString("code")?.takeIf { it.isNotBlank() }
    } catch (_: Exception) { null }

    private fun extensionFor(mimeType: String): String = when (mimeType.lowercase()) {
        "application/pdf" -> "pdf"
        "image/jpeg" -> "jpg"
        "image/png" -> "png"
        "image/webp" -> "webp"
        else -> throw IllegalArgumentException("Unsupported document type.")
    }

    private data class GatewayResponse(val status: Int, val body: String)

    private companion object {
        const val MAX_RESPONSE_BYTES = 64 * 1024
        val SHA256_REGEX = Regex("^[a-fA-F0-9]{64}$")
        val UUID_REGEX = Regex("^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$")
    }
}

class OcrGatewayException(
    val httpStatus: Int?,
    val errorCode: String?,
    message: String
) : Exception(message)
