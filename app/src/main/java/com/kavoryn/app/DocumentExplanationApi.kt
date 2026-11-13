package com.kavoryn.app

import org.json.JSONArray
import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID

data class DocumentExplanation(
    val documentId: String,
    val provider: ExplanationProvider,
    val language: ExplanationLanguage,
    val summary: String,
    val detailedExplanation: String,
    val actions: List<String>,
    val keyFacts: List<ExplanationKeyFact>,
    val requestId: String?
)

data class ExplanationKeyFact(
    val label: String,
    val value: String,
    val sourceQuote: String
)

class DocumentExplanationApiException(
    val httpStatus: Int?,
    val errorCode: String?,
    message: String
) : Exception(message)

/**
 * Small authenticated client for POST /v1/documents/{id}/explain.
 *
 * Call from a background dispatcher/thread. The caller must obtain the current
 * user's Supabase access token and pass it here; never pass a service-role key.
 * Provider API keys remain exclusively on the Kavoryn server.
 */
class DocumentExplanationApi(
    private val baseUrl: String,
    private val connectTimeoutMs: Int = 10_000,
    private val readTimeoutMs: Int = 90_000
) {
    fun explain(
        documentId: String,
        accessToken: String,
        provider: ExplanationProvider,
        language: ExplanationLanguage
    ): DocumentExplanation {
        require(UUID_REGEX.matches(documentId)) { "A valid document ID is required." }
        require(accessToken.isNotBlank()) { "A signed-in session is required." }
        val root = baseUrl.trimEnd('/')
        require(root.startsWith("https://", ignoreCase = true)) {
            "The Kavoryn API must use HTTPS."
        }

        val connection = (URL("$root/v1/documents/$documentId/explain").openConnection()
            as HttpURLConnection).apply {
            requestMethod = "POST"
            connectTimeout = connectTimeoutMs
            readTimeout = readTimeoutMs
            doOutput = true
            setRequestProperty("Authorization", "Bearer $accessToken")
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Cache-Control", "no-store")
        }

        try {
            val request = JSONObject()
                .put("confirmed", true)
                .put("provider", provider.apiValue)
                .put("language", language.apiValue)
                .toString()
                .toByteArray(Charsets.UTF_8)

            connection.outputStream.use { it.write(request) }
            val status = connection.responseCode
            val responseStream = if (status in 200..299) {
                connection.inputStream
            } else {
                connection.errorStream
            } ?: throw DocumentExplanationApiException(
                status, null, "The explanation service returned no response."
            )

            val responseBytes = responseStream.use { input ->
                val output = ByteArrayOutputStream()
                val buffer = ByteArray(8192)
                var total = 0
                while (true) {
                    val count = input.read(buffer)
                    if (count < 0) break
                    total += count
                    if (total > MAX_RESPONSE_BYTES) {
                        throw DocumentExplanationApiException(
                            status, null, "The explanation response was too large."
                        )
                    }
                    output.write(buffer, 0, count)
                }
                output.toByteArray()
            }
            val json = JSONObject(responseBytes.toString(Charsets.UTF_8))
            if (status !in 200..299) {
                val error = json.optJSONObject("error")
                throw DocumentExplanationApiException(
                    status,
                    error?.optString("code")?.takeIf { it.isNotBlank() },
                    error?.optString("message")?.takeIf { it.isNotBlank() }
                        ?: "The document could not be explained."
                )
            }

            return DocumentExplanation(
                documentId = json.getString("document_id"),
                provider = provider,
                language = language,
                summary = json.getString("summary"),
                detailedExplanation = json.getString("detailed_explanation"),
                actions = json.getJSONArray("actions").toStringList(),
                keyFacts = json.getJSONArray("key_facts").toKeyFacts(),
                requestId = json.optString("request_id").takeIf { it.isNotBlank() }
            )
        } catch (error: DocumentExplanationApiException) {
            throw error
        } catch (_: Exception) {
            // Avoid leaking URLs, access tokens, document content or server internals.
            throw DocumentExplanationApiException(
                null, null, "The explanation request failed. Check your connection and try again."
            )
        } finally {
            connection.disconnect()
        }
    }

    private fun JSONArray.toStringList(): List<String> =
        (0 until length()).mapNotNull { index ->
            optString(index).takeIf { it.isNotBlank() }
        }

    private fun JSONArray.toKeyFacts(): List<ExplanationKeyFact> =
        (0 until length()).mapNotNull { index ->
            val item = optJSONObject(index) ?: return@mapNotNull null
            ExplanationKeyFact(
                label = item.optString("label"),
                value = item.optString("value"),
                sourceQuote = item.optString("source_quote")
            )
        }

    private companion object {
        const val MAX_RESPONSE_BYTES = 1024 * 1024
        val UUID_REGEX = Regex(
            "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$"
        )
    }
}
