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
        language: ExplanationLanguage,
        originalOcrText: String
    ): DocumentExplanation {
        require(UUID_REGEX.matches(documentId)) { "A valid document ID is required." }
        require(accessToken.isNotBlank()) { "A signed-in session is required." }
        require(originalOcrText.isNotBlank() && originalOcrText.length <= MAX_SOURCE_TEXT_CHARS) {
            "The original OCR text is unavailable for source verification."
        }
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
            setRequestProperty("Pragma", "no-cache")
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
            val responseBody = responseBytes.toString(Charsets.UTF_8)
            if (status !in 200..299) {
                val json = JSONObject(responseBody)
                val error = json.optJSONObject("error")
                throw DocumentExplanationApiException(
                    status,
                    error?.optString("code")?.takeIf { it.isNotBlank() },
                    error?.optString("message")?.takeIf { it.isNotBlank() }
                        ?: "The document could not be explained."
                )
            }

            return parseDocumentExplanationResponse(
                responseBody = responseBody,
                expectedDocumentId = documentId,
                originalOcrText = originalOcrText,
                provider = provider,
                language = language
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

    private companion object {
        const val MAX_RESPONSE_BYTES = 1024 * 1024
        const val MAX_SOURCE_TEXT_CHARS = 200_000
        val UUID_REGEX = Regex(
            "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$"
        )
    }
}

/**
 * Parses explanation JSON and checks every key-fact quote against the original OCR text.
 * This is a second client-side guard: the backend also validates exact source quotes.
 */
internal fun parseDocumentExplanationResponse(
    responseBody: String,
    expectedDocumentId: String,
    originalOcrText: String,
    provider: ExplanationProvider,
    language: ExplanationLanguage
): DocumentExplanation {
    require(UUID_REGEX.matches(expectedDocumentId)) { "Expected document ID is invalid." }
    require(originalOcrText.isNotBlank()) { "Original OCR text is required." }

    val json = JSONObject(responseBody)
    val responseDocumentId = json.getString("document_id")
    require(responseDocumentId == expectedDocumentId) {
        "The explanation belongs to a different document."
    }

    val summary = json.getString("summary").trim()
    val detail = json.getString("detailed_explanation").trim()
    require(summary.isNotEmpty() && summary.length <= 2_500) { "The explanation summary is invalid." }
    require(detail.isNotEmpty() && detail.length <= 12_000) { "The detailed explanation is invalid." }

    val actionsJson = json.getJSONArray("actions")
    require(actionsJson.length() <= MAX_ACTIONS) { "The explanation contains too many suggested actions." }
    val actions = actionsJson.toStringList()
    require(actionsJson.length() == actions.size && actions.all { it.length <= MAX_ACTION_CHARS }) {
        "A suggested action is invalid."
    }

    val factsJson = json.getJSONArray("key_facts")
    require(factsJson.length() <= MAX_KEY_FACTS) { "The explanation contains too many key details." }
    val facts = factsJson.toKeyFacts(originalOcrText)
    require(factsJson.length() == facts.size) { "A key detail could not be verified." }

    return DocumentExplanation(
        documentId = responseDocumentId,
        provider = provider,
        language = language,
        summary = summary,
        detailedExplanation = detail,
        actions = actions,
        keyFacts = facts,
        requestId = json.optString("request_id").takeIf { it.isNotBlank() }
    )
}

private fun JSONArray.toStringList(): List<String> =
    (0 until length()).map { index ->
        getString(index).trim().also {
            require(it.isNotEmpty()) { "A suggested action is empty." }
        }
    }

private fun JSONArray.toKeyFacts(originalOcrText: String): List<ExplanationKeyFact> =
    (0 until length()).map { index ->
        val item = getJSONObject(index)
        val label = item.getString("label").trim()
        val value = item.getString("value").trim()
        val sourceQuote = item.getString("source_quote")
        require(label.isNotEmpty() && label.length <= MAX_FACT_LABEL_CHARS) {
            "A key-detail label is invalid."
        }
        require(value.isNotEmpty() && value.length <= MAX_FACT_VALUE_CHARS) {
            "A key-detail value is invalid."
        }
        require(sourceQuote.isNotBlank() && sourceQuote.length <= MAX_SOURCE_QUOTE_CHARS &&
            originalOcrText.contains(sourceQuote)
        ) {
            "A key detail does not match the original OCR text."
        }
        ExplanationKeyFact(label, value, sourceQuote)
    }

private const val MAX_ACTIONS = 12
private const val MAX_ACTION_CHARS = 500
private const val MAX_KEY_FACTS = 20
private const val MAX_FACT_LABEL_CHARS = 100
private const val MAX_FACT_VALUE_CHARS = 700
private const val MAX_SOURCE_QUOTE_CHARS = 700
private val UUID_REGEX = Regex(
    "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$"
)
