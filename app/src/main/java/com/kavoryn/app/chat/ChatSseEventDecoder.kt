package com.kavoryn.app.chat

import org.json.JSONObject

/**
 * Decodes one complete server-sent event from the authenticated Chat stream.
 * The backend also enforces response limits; this client-side bound protects
 * the UI parser if a proxy or unexpected server response misbehaves.
 */
internal class ChatSseEventDecoder {
    fun decode(raw: String, onDelta: (String) -> Unit): Boolean {
        if (raw.length > MAX_EVENT_CHARS) {
            throw ChatApiException(null, "event_too_large", "The chat response contained an oversized event.")
        }
        val event = try {
            JSONObject(raw)
        } catch (_: Exception) {
            throw ChatApiException(null, "invalid_stream", "The chat response could not be read.")
        }
        return when (event.optString("type")) {
            "delta" -> {
                val text = event.optString("text")
                if (text.isNotEmpty()) onDelta(text)
                false
            }
            "completed" -> true
            "error" -> {
                val code = event.optString("code").takeIf { it.isNotBlank() }
                val message = event.optString("message")
                    .takeIf { it.isNotBlank() }
                    ?: "The AI response could not be completed."
                throw ChatApiException(null, code, message)
            }
            else -> false
        }
    }

    companion object {
        const val MAX_EVENT_CHARS = 65_536
    }
}
