package com.kavoryn.app.chat

import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.net.HttpURLConnection
import java.net.URL

enum class ChatProvider(val wireValue: String, val label: String) {
    OPENAI("openai", "OpenAI"),
    GEMINI("gemini", "Gemini")
}

data class ChatMessage(
    val id: String,
    val role: String,
    val content: String,
    val status: String
)

data class ChatConversation(
    val id: String,
    val title: String,
    val provider: ChatProvider,
    val language: String,
    val messages: List<ChatMessage>
)

class ChatApiException(val status: Int?, val code: String?, message: String) : Exception(message)

interface ChatHistoryApi {
    fun listConversations(accessToken: String): List<ChatConversation>
    fun getConversation(conversationId: String, accessToken: String): ChatConversation
}

class ChatApi(
    private val baseUrl: String,
    private val connectTimeoutMs: Int = 10_000,
    private val readTimeoutMs: Int = 90_000
) : ChatHistoryApi {
    fun recordProviderConsent(provider: ChatProvider, accessToken: String) {
        request(
            path = "/v1/chat/consents",
            method = "POST",
            accessToken = accessToken,
            body = JSONObject().put("provider", provider.wireValue).put("confirmed", true)
        )
    }

    fun createConversation(
        provider: ChatProvider,
        language: String,
        accessToken: String,
        title: String? = null
    ): ChatConversation {
        val body = JSONObject()
            .put("provider", provider.wireValue)
            .put("language", language)
        if (!title.isNullOrBlank()) body.put("title", title.take(120))
        val root = request("/v1/chat/conversations", "POST", accessToken, body)
        return parseConversation(root.getJSONObject("conversation"))
    }

    override fun listConversations(accessToken: String): List<ChatConversation> {
        val root = request("/v1/chat/conversations?limit=30", "GET", accessToken)
        return parseConversations(root.optJSONArray("conversations") ?: JSONArray())
    }

    override fun getConversation(conversationId: String, accessToken: String): ChatConversation {
        requireUuid(conversationId)
        val root = request("/v1/chat/conversations/$conversationId", "GET", accessToken)
        return parseConversation(root.getJSONObject("conversation"))
    }

    fun deleteConversation(conversationId: String, accessToken: String) {
        requireUuid(conversationId)
        request("/v1/chat/conversations/$conversationId", "DELETE", accessToken)
    }

    /** Streams only the explicitly selected conversation provider; provider secrets never enter Android. */
    fun streamMessage(
        conversationId: String,
        message: String,
        accessToken: String,
        onDelta: (String) -> Unit
    ) {
        requireUuid(conversationId)
        require(message.isNotBlank() && message.codePointCount(0, message.length) <= 24_000)
        val connection = open("/v1/chat/conversations/$conversationId/messages", "POST", accessToken).apply {
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
            setRequestProperty("Accept", "text/event-stream")
            doOutput = true
        }
        try {
            val payload = JSONObject().put("message", message).toString()
            connection.outputStream.use { it.write(payload.toByteArray(Charsets.UTF_8)) }
            val status = connection.responseCode
            if (status !in 200..299) throw parseError(status, connection.errorStream?.bufferedReader()?.use { it.readText() })
            var completed = false
            BufferedReader(InputStreamReader(connection.inputStream, Charsets.UTF_8)).use { reader ->
                var eventData = StringBuilder()
                while (true) {
                    val line = reader.readLine() ?: break
                    if (line.isEmpty()) {
                        if (eventData.isNotEmpty()) {
                            if (handleEvent(eventData.toString(), onDelta)) completed = true
                            eventData = StringBuilder()
                        }
                    } else if (line.startsWith("data:")) {
                        if (eventData.isNotEmpty()) eventData.append('\n')
                        eventData.append(line.removePrefix("data:").trimStart())
                    }
                }
                if (eventData.isNotEmpty() && handleEvent(eventData.toString(), onDelta)) completed = true
            }
            if (!completed) throw ChatApiException(null, "incomplete_stream", "The AI response ended before completion. Please try again.")
        } catch (error: ChatApiException) {
            throw error
        } catch (error: Exception) {
            throw ChatApiException(null, null, "The chat connection was interrupted. Please try again.")
        } finally {
            connection.disconnect()
        }
    }

    private fun handleEvent(raw: String, onDelta: (String) -> Unit): Boolean {
        val event = try { JSONObject(raw) } catch (_: Exception) {
            throw ChatApiException(null, "invalid_stream", "The chat response could not be read.")
        }
        when (event.optString("type")) {
            "delta" -> onDelta(event.optString("text"))
            "completed" -> return true
            "error" -> throw ChatApiException(null, event.optString("code"), event.optString("message", "The AI response could not be completed."))
        }
        return false
    }

    private fun request(
        path: String,
        method: String,
        accessToken: String,
        body: JSONObject? = null
    ): JSONObject {
        val connection = open(path, method, accessToken).apply {
            if (body != null) {
                setRequestProperty("Content-Type", "application/json; charset=utf-8")
                doOutput = true
            }
        }
        try {
            if (body != null) connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            val status = connection.responseCode
            val response = if (status in 200..299) connection.inputStream?.bufferedReader()?.use { it.readText() }.orEmpty()
                else connection.errorStream?.bufferedReader()?.use { it.readText() }
            if (status !in 200..299) throw parseError(status, response)
            return if (response.isNullOrBlank()) JSONObject() else JSONObject(response)
        } catch (error: ChatApiException) {
            throw error
        } catch (_: Exception) {
            throw ChatApiException(null, null, "Kavoryn Chat is temporarily unavailable. Please try again.")
        } finally {
            connection.disconnect()
        }
    }

    private fun open(path: String, method: String, accessToken: String): HttpURLConnection {
        require(baseUrl.startsWith("https://", ignoreCase = true)) {
            "A verified HTTPS Kavoryn API address is required."
        }
        require(accessToken.isNotBlank()) { "Please sign in to use Kavoryn Chat." }
        val root = baseUrl.trimEnd('/')
        return (URL(root + path).openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = connectTimeoutMs
            readTimeout = readTimeoutMs
            doInput = true
            setRequestProperty("Authorization", "Bearer $accessToken")
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Cache-Control", "no-store")
        }
    }

    private fun parseError(status: Int, body: String?): ChatApiException {
        val json = try { body?.let { JSONObject(it) } } catch (_: Exception) { null }
        val error = json?.optJSONObject("error")
        val code = error?.optString("code")?.takeIf { it.isNotBlank() }
        val message = when (code) {
            "chat_disabled" -> "Kavoryn Chat is not enabled on the server yet."
            "chat_provider_consent_required" -> "Confirm the selected AI provider before sending messages."
            "chat_rate_limited" -> "Please wait a moment before sending another message."
            "unauthorized" -> "Your sign-in session has expired. Please sign in again."
            else -> error?.optString("message")?.takeIf { it.isNotBlank() }
                ?: "Kavoryn Chat could not complete this request."
        }
        return ChatApiException(status, code, message)
    }

    private fun parseConversation(json: JSONObject): ChatConversation {
        val provider = ChatProvider.entries.firstOrNull { it.wireValue == json.optString("provider") }
            ?: throw ChatApiException(null, "unsupported_provider", "This conversation uses an unsupported AI provider.")
        val messages = parseMessages(json.optJSONArray("messages") ?: JSONArray())
        return ChatConversation(
            id = json.getString("id"),
            title = json.optString("title", "New conversation"),
            provider = provider,
            language = json.optString("language", "en"),
            messages = messages
        )
    }

    private fun parseConversations(array: JSONArray): List<ChatConversation> =
        (0 until array.length()).mapNotNull { index ->
            runCatching { parseConversation(array.getJSONObject(index)) }.getOrNull()
        }

    private fun parseMessages(array: JSONArray): List<ChatMessage> =
        (0 until array.length()).mapNotNull { index ->
            val item = array.optJSONObject(index) ?: return@mapNotNull null
            ChatMessage(
                id = item.optString("id"),
                role = item.optString("role"),
                content = item.optString("content"),
                status = item.optString("status")
            )
        }

    private fun requireUuid(value: String) {
        require(Regex("^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$").matches(value))
    }
}
