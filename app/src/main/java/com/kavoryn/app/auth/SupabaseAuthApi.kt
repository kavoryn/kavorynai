package com.kavoryn.app.auth

import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.net.HttpURLConnection
import java.net.URL

data class SupabaseAuthSession(
    val accessToken: String,
    val refreshToken: String,
    val tokenType: String,
    val expiresAtEpochSeconds: Long,
    val userId: String,
    val email: String?
)

class SupabaseAuthException(
    val httpStatus: Int?,
    val errorCode: String?,
    message: String
) : Exception(message)

/**
 * Minimal Supabase Auth REST client for the Android public client.
 *
 * The publishable key is a client identifier, not a user credential. User passwords
 * are sent only over HTTPS to Supabase Auth. Never pass a service-role/secret key here.
 * Returned tokens are held by the caller; persistence and refresh scheduling are
 * deliberately separate responsibilities.
 */
class SupabaseAuthApi(
    private val projectUrl: String,
    private val publishableKey: String,
    private val connectTimeoutMs: Int = 10_000,
    private val readTimeoutMs: Int = 20_000
) {
    fun signInWithPassword(email: String, password: String): SupabaseAuthSession {
        require(email.trim().isNotEmpty()) { "Email is required." }
        require(password.isNotEmpty()) { "Password is required." }
        return postToken(
            grantType = "password",
            body = JSONObject().put("email", email.trim()).put("password", password)
        )
    }

    fun refreshSession(refreshToken: String): SupabaseAuthSession {
        require(refreshToken.isNotBlank()) { "A refresh token is required." }
        return postToken(
            grantType = "refresh_token",
            body = JSONObject().put("refresh_token", refreshToken)
        )
    }

    fun signOut(accessToken: String) {
        require(accessToken.isNotBlank()) { "A signed-in session is required." }
        val root = validatedSupabaseAuthRoot(projectUrl)
        val connection = openConnection("$root/auth/v1/logout").apply {
            requestMethod = "POST"
            setRequestProperty("Authorization", "Bearer $accessToken")
        }
        try {
            val status = connection.responseCode
            if (status !in 200..299) {
                throw SupabaseAuthException(
                    status, null, "Sign-out could not be completed. Please try again."
                )
            }
        } catch (error: SupabaseAuthException) {
            throw error
        } catch (_: Exception) {
            throw SupabaseAuthException(
                null, null, "Sign-out failed. Check your connection and try again."
            )
        } finally {
            connection.disconnect()
        }
    }

    private fun postToken(grantType: String, body: JSONObject): SupabaseAuthSession {
        val root = validatedSupabaseAuthRoot(projectUrl)
        val connection = openConnection("$root/auth/v1/token?grant_type=$grantType").apply {
            requestMethod = "POST"
            doOutput = true
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
        }
        try {
            connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream
            if (stream == null) {
                throw SupabaseAuthException(status, null, "Supabase Auth returned no response.")
            }
            val bytes = stream.use { input ->
                val output = ByteArrayOutputStream()
                val buffer = ByteArray(4096)
                var total = 0
                while (true) {
                    val count = input.read(buffer)
                    if (count < 0) break
                    total += count
                    if (total > MAX_RESPONSE_BYTES) {
                        throw SupabaseAuthException(
                            status, null, "The authentication response was too large."
                        )
                    }
                    output.write(buffer, 0, count)
                }
                output.toByteArray()
            }
            if (status !in 200..299) {
                val code = try {
                    JSONObject(bytes.toString(Charsets.UTF_8))
                        .optString("code").takeIf { it.isNotBlank() }
                        ?: JSONObject(bytes.toString(Charsets.UTF_8))
                            .optString("error_code").takeIf { it.isNotBlank() }
                } catch (_: Exception) { null }
                throw SupabaseAuthException(
                    status, code, "Sign-in could not be completed. Check your details and try again."
                )
            }
            return parseSupabaseAuthSession(bytes.toString(Charsets.UTF_8))
        } catch (error: SupabaseAuthException) {
            throw error
        } catch (_: Exception) {
            throw SupabaseAuthException(
                null, null, "Authentication failed. Check your connection and try again."
            )
        } finally {
            connection.disconnect()
        }
    }

    private fun openConnection(url: String): HttpURLConnection {
        require(publishableKey.isNotBlank()) { "A Supabase publishable key is required." }
        return (URL(url).openConnection() as HttpURLConnection).apply {
            connectTimeout = connectTimeoutMs
            readTimeout = readTimeoutMs
            doInput = true
            setRequestProperty("apikey", publishableKey)
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Cache-Control", "no-store")
        }
    }

    private companion object {
        const val MAX_RESPONSE_BYTES = 64 * 1024
    }
}

internal fun validatedSupabaseAuthRoot(projectUrl: String): String {
    val root = projectUrl.trim().trimEnd('/')
    require(root.startsWith("https://", ignoreCase = true)) {
        "Supabase Auth must use HTTPS."
    }
    val parsed = java.net.URI(root)
    require(
        !parsed.host.isNullOrBlank() && parsed.userInfo == null &&
            parsed.rawQuery == null && parsed.rawFragment == null &&
            (parsed.rawPath.isNullOrEmpty() || parsed.rawPath == "/")
    ) { "Provide the Supabase project origin, without a path or credentials." }
    return root
}

internal fun parseSupabaseAuthSession(
    responseBody: String,
    nowEpochSeconds: Long = System.currentTimeMillis() / 1000
): SupabaseAuthSession {
    val json = JSONObject(responseBody)
    val user = json.getJSONObject("user")
    val accessToken = json.getString("access_token")
    val refreshToken = json.getString("refresh_token")
    val userId = user.getString("id")
    require(accessToken.isNotBlank() && refreshToken.isNotBlank() && userId.isNotBlank()) {
        "Supabase Auth returned an incomplete session."
    }
    val expiresAt = json.optLong("expires_at").takeIf { it > 0 }
        ?: (nowEpochSeconds + json.optLong("expires_in", 3600).coerceAtLeast(0))
    return SupabaseAuthSession(
        accessToken = accessToken,
        refreshToken = refreshToken,
        tokenType = json.optString("token_type", "bearer").ifBlank { "bearer" },
        expiresAtEpochSeconds = expiresAt,
        userId = userId,
        email = user.optString("email").takeIf { it.isNotBlank() }
    )
}

/** Clear persisted credentials only when Supabase explicitly rejects the refresh token. */
internal fun shouldClearStoredSessionAfterRefreshFailure(error: SupabaseAuthException): Boolean =
    error.httpStatus == 400 || error.httpStatus == 401
