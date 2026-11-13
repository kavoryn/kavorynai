package com.kavoryn.app.qa

import org.json.JSONArray
import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.net.HttpURLConnection
import java.net.URI
import java.net.URL

enum class AiQuestionProvider(val wireValue: String, val displayName: String) {
    OPENAI("openai", "OpenAI"),
    GEMINI("gemini", "Gemini")
}

data class DocumentQuestionAnswer(
    val answer: String,
    val sourceExcerpts: List<String>,
    val insufficientInformation: Boolean
)

class DocumentQuestionException(message: String, val httpStatus: Int? = null) : Exception(message)

/** Authenticated, no-store client for the owner-checked document Q&A endpoint. */
class DocumentQuestionApi(
    private val apiBaseUrl: String,
    private val connectTimeoutMs: Int = 10_000,
    private val readTimeoutMs: Int = 30_000
) {
    fun ask(
        documentId: String,
        accessToken: String,
        question: String,
        provider: AiQuestionProvider,
        language: String,
        confirmed: Boolean
    ): DocumentQuestionAnswer {
        require(UUID_PATTERN.matches(documentId)) { "A valid document reference is required." }
        require(accessToken.isNotBlank()) { "A signed-in session is required." }
        require(question.trim().isNotEmpty() && question.trim().length <= MAX_QUESTION_CHARS) {
            "Enter a question between 1 and 1,000 characters."
        }
        require(language in SUPPORTED_LANGUAGES) { "Choose a supported answer language." }
        require(confirmed) { "Confirm the AI processing notice before continuing." }

        val root = validatedApiRoot(apiBaseUrl)
        val connection = (URL("$root/v1/documents/$documentId/ask").openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            connectTimeout = connectTimeoutMs
            readTimeout = readTimeoutMs
            doInput = true
            doOutput = true
            setRequestProperty("Authorization", "Bearer $accessToken")
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Cache-Control", "no-store")
            setRequestProperty("Pragma", "no-cache")
        }
        try {
            val payload = JSONObject()
                .put("question", question.trim())
                .put("provider", provider.wireValue)
                .put("language", language)
                .put("confirmed", true)
            connection.outputStream.use { it.write(payload.toString().toByteArray(Charsets.UTF_8)) }
            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream
            if (stream == null) throw DocumentQuestionException(
                "The document question service returned no response.", status
            )
            val body = stream.use { input ->
                val output = ByteArrayOutputStream()
                val buffer = ByteArray(4096)
                var total = 0
                while (true) {
                    val count = input.read(buffer)
                    if (count < 0) break
                    total += count
                    if (total > MAX_RESPONSE_BYTES) {
                        throw DocumentQuestionException("The answer was too large to display.", status)
                    }
                    output.write(buffer, 0, count)
                }
                output.toString(Charsets.UTF_8.name())
            }
            if (status !in 200..299) {
                val code = try { JSONObject(body).optJSONObject("error")?.optString("code") } catch (_: Exception) { null }
                val message = when (code) {
                    "document_qa_disabled" -> "Document questions are not enabled on the server yet."
                    "document_result_not_found" -> "This document result is no longer available."
                    "rate_limited" -> "You have reached the question limit. Please wait and try again."
                    "ai_consent_storage_unavailable" -> "Your consent could not be recorded. No question was sent to the AI provider."
                    "ai_provider_unavailable" -> "The selected AI provider is temporarily unavailable."
                    "unauthorized" -> "Your sign-in session has expired. Please sign in again."
                    else -> "Kavoryn could not answer this question. Please try again later."
                }
                throw DocumentQuestionException(message, status)
            }
            return parseDocumentQuestionAnswer(body, documentId)
        } catch (error: DocumentQuestionException) {
            throw error
        } catch (_: Exception) {
            throw DocumentQuestionException("The question could not be sent. Check your connection and try again.")
        } finally {
            connection.disconnect()
        }
    }

    private companion object {
        const val MAX_QUESTION_CHARS = 1_000
        const val MAX_RESPONSE_BYTES = 128 * 1024
        val UUID_PATTERN = Regex("(?i)^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
        val SUPPORTED_LANGUAGES = setOf("en", "ha", "ar", "fr")
    }
}

internal fun validatedApiRoot(apiBaseUrl: String): String {
    val root = apiBaseUrl.trim().trimEnd('/')
    require(root.startsWith("https://", ignoreCase = true)) { "The Kavoryn API must use HTTPS." }
    val parsed = URI(root)
    require(!parsed.host.isNullOrBlank() && parsed.userInfo == null &&
        parsed.rawQuery == null && parsed.rawFragment == null) {
        "Provide the Kavoryn API base URL without credentials, query parameters or fragments."
    }
    return root
}

internal fun parseDocumentQuestionAnswer(responseBody: String, expectedDocumentId: String? = null): DocumentQuestionAnswer {
    val json = JSONObject(responseBody)
    if (expectedDocumentId != null) {\n        require(json.optString("document_id") == expectedDocumentId) { "The answer belongs to a different document." }\n    }\n    val answer = json.getString("answer").trim()
    require(answer.isNotEmpty() && answer.length <= 8_000) { "The answer is empty or too large." }
    val excerptsJson = json.optJSONArray("source_excerpts") ?: JSONArray()
    require(excerptsJson.length() <= 5) { "The response contains too many source excerpts." }
    val excerpts = (0 until excerptsJson.length()).map { index ->
        excerptsJson.getString(index).also {
            require(it.isNotBlank() && it.length <= 2_000) { "A source excerpt is invalid." }
        }
    }
    return DocumentQuestionAnswer(
        answer = answer,
        sourceExcerpts = excerpts,
        insufficientInformation = json.optBoolean("insufficient_information", false)
    )
}
