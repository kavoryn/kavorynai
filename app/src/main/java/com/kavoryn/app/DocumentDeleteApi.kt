package com.kavoryn.app

import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID

class DocumentDeleteApiException(
    val httpStatus: Int?,
    val errorCode: String?,
    message: String
) : Exception(message)

/**
 * Requests owner-scoped deletion through the authenticated Kavoryn API.
 * Supabase privileged credentials are never present in the Android client.
 */
class DocumentDeleteApi(
    private val baseUrl: String,
    private val connectTimeoutMs: Int = 10_000,
    private val readTimeoutMs: Int = 20_000
) {
    fun deleteDocument(documentId: String, accessToken: String) {
        validateDocumentDeleteRequest(baseUrl, documentId, accessToken)
        val root = baseUrl.trimEnd('/')
        val connection = (URL("$root/v1/documents/$documentId").openConnection()
            as HttpURLConnection).apply {
            requestMethod = "DELETE"
            connectTimeout = connectTimeoutMs
            readTimeout = readTimeoutMs
            instanceFollowRedirects = false
            setRequestProperty("Authorization", "Bearer $accessToken")
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Cache-Control", "no-store")
        }

        try {
            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream
            val body = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }.orEmpty()
            val json = runCatching { JSONObject(body) }.getOrNull()

            if (status !in 200..299) {
                val error = json?.optJSONObject("error")
                val message = when (status) {
                    401, 403 -> "Your sign-in session is no longer valid. Please sign in again."
                    404 -> "This document was not found for your account."
                    503 -> error?.optString("message")?.takeIf { it.isNotBlank() }
                        ?: "Secure deletion is temporarily unavailable. Please try again."
                    else -> "The document could not be deleted. Please try again."
                }
                throw DocumentDeleteApiException(
                    status,
                    error?.optString("code")?.takeIf { it.isNotBlank() },
                    message
                )
            }

            val deletedId = json?.optString("document_id").orEmpty()
            if (json?.optBoolean("deleted", false) != true ||
                !deletedId.equals(documentId, ignoreCase = true)
            ) {
                throw DocumentDeleteApiException(
                    status, null, "The deletion service returned an unexpected response."
                )
            }
        } catch (error: DocumentDeleteApiException) {
            throw error
        } catch (_: Exception) {
            throw DocumentDeleteApiException(
                null, null, "The document could not be deleted because the server could not be reached."
            )
        } finally {
            connection.disconnect()
        }
    }
}

internal fun validateDocumentDeleteRequest(
    baseUrl: String,
    documentId: String,
    accessToken: String
) {
    require(UUID_REGEX.matches(documentId)) { "A valid document ID is required." }
    require(accessToken.isNotBlank()) { "A signed-in session is required." }
    require(baseUrl.trim().startsWith("https://", ignoreCase = true)) {
        "The Kavoryn API must use HTTPS."
    }
}

private val UUID_REGEX = Regex(
    "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$"
)
