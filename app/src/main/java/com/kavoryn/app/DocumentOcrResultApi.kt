package com.kavoryn.app

import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID

data class DocumentOcrResult(
    val documentId: String,
    val text: String,
    val uncertainSpans: List<OcrUncertainSpan>,
    val detectedLanguage: String,
    val requestId: String?
)

data class OcrUncertainSpan(
    /** UTF-16 offsets suitable for Android TextRange and Kotlin String indexing. */
    val start: Int,
    val endExclusive: Int,
    val confidence: Float,
    val reason: String
)

class DocumentOcrResultApiException(
    val httpStatus: Int?,
    val errorCode: String?,
    message: String
) : Exception(message)

/**
 * Retrieves the owner's completed OCR result over HTTPS.
 * The server enforces ownership and decrypts the result; no privileged key is used here.
 */
class DocumentOcrResultApi(
    private val baseUrl: String,
    private val connectTimeoutMs: Int = 10_000,
    private val readTimeoutMs: Int = 30_000
) {
    fun getResult(documentId: String, accessToken: String): DocumentOcrResult {
        require(UUID_REGEX.matches(documentId)) { "A valid document ID is required." }
        require(accessToken.isNotBlank()) { "A signed-in session is required." }
        val root = baseUrl.trimEnd('/')
        require(root.startsWith("https://", ignoreCase = true)) {
            "The Kavoryn API must use HTTPS."
        }

        val connection = (URL("$root/v1/documents/$documentId/result").openConnection()
            as HttpURLConnection).apply {
            requestMethod = "GET"
            connectTimeout = connectTimeoutMs
            readTimeout = readTimeoutMs
            setRequestProperty("Authorization", "Bearer $accessToken")
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Cache-Control", "no-store")
        }

        try {
            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream
                ?: throw DocumentOcrResultApiException(
                    status, null, "The OCR result service returned no response."
                )
            val bytes = stream.use { input ->
                val output = ByteArrayOutputStream()
                val buffer = ByteArray(8192)
                var total = 0
                while (true) {
                    val count = input.read(buffer)
                    if (count < 0) break
                    total += count
                    if (total > MAX_RESPONSE_BYTES) {
                        throw DocumentOcrResultApiException(
                            status, null, "The OCR result was too large."
                        )
                    }
                    output.write(buffer, 0, count)
                }
                output.toByteArray()
            }
            val json = JSONObject(bytes.toString(Charsets.UTF_8))
            if (status !in 200..299) {
                val error = json.optJSONObject("error")
                throw DocumentOcrResultApiException(
                    status,
                    error?.optString("code")?.takeIf { it.isNotBlank() },
                    error?.optString("message")?.takeIf { it.isNotBlank() }
                        ?: "The OCR result is not available yet."
                )
            }

            val responseDocumentId = json.getString("document_id")
            require(responseDocumentId.equals(documentId, ignoreCase = true)) {
                "The OCR result document reference did not match."
            }
            val text = json.getString("text")
            val spansJson = json.getJSONArray("uncertain_spans")
            val spans = (0 until spansJson.length()).map { index ->
                val item = spansJson.getJSONObject(index)
                val startCodePoint = item.getInt("start")
                val endCodePoint = item.getInt("end")
                require(startCodePoint >= 0 && endCodePoint > startCodePoint) {
                    "The OCR result contains an invalid uncertainty range."
                }
                val range = codePointRangeToUtf16(text, startCodePoint, endCodePoint)
                OcrUncertainSpan(
                    start = range.first,
                    endExclusive = range.second,
                    confidence = item.getDouble("confidence").toFloat().also {
                        require(it.isFinite() && it in 0f..1f) {
                            "The OCR result contains invalid confidence data."
                        }
                    },
                    reason = item.getString("reason")
                )
            }
            return DocumentOcrResult(
                documentId = responseDocumentId,
                text = text,
                uncertainSpans = spans,
                detectedLanguage = json.optString("detected_language", "und"),
                requestId = json.optString("request_id").takeIf { it.isNotBlank() }
            )
        } catch (error: DocumentOcrResultApiException) {
            throw error
        } catch (_: Exception) {
            throw DocumentOcrResultApiException(
                null, null, "The OCR result could not be read. Please try again."
            )
        } finally {
            connection.disconnect()
        }
    }

    private companion object {
        const val MAX_RESPONSE_BYTES = 2 * 1024 * 1024
        val UUID_REGEX = Regex(
            "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$"
        )
    }
}


/** Convert server Unicode-code-point offsets to Android/Kotlin UTF-16 indices. */
internal fun codePointRangeToUtf16(text: String, start: Int, endExclusive: Int): Pair<Int, Int> {
    require(start >= 0 && endExclusive > start) {
        "The OCR result contains an invalid uncertainty range."
    }
    val codePointCount = text.codePointCount(0, text.length)
    require(endExclusive <= codePointCount) {
        "The OCR result contains an out-of-range uncertainty span."
    }
    return text.offsetByCodePoints(0, start) to text.offsetByCodePoints(0, endExclusive)
}
