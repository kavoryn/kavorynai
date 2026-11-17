package com.kavoryn.app.auth

import com.kavoryn.app.BuildConfig
import java.net.URI
import java.net.URLDecoder

data class SupabaseAuthCallback(
    val accessToken: String,
    val refreshToken: String,
    val type: String,
    val expiresAtEpochSeconds: Long
)

/**
 * Parses only the configured app callback and only supported one-time email
 * verification/recovery callbacks. Tokens are returned to in-memory caller state;
 * this helper never persists or logs them.
 */
internal fun parseSupabaseAuthCallback(
    callbackUri: String?,
    nowEpochSeconds: Long = System.currentTimeMillis() / 1000,
    expectedApplicationId: String = BuildConfig.APPLICATION_ID
): SupabaseAuthCallback? {
    if (callbackUri.isNullOrBlank() || callbackUri.length > 16_384) return null

    return try {
        val uri = URI(callbackUri)
        if (uri.scheme != expectedApplicationId ||
            uri.host != "auth-callback" ||
            uri.userInfo != null ||
            uri.port != -1 ||
            (!uri.rawPath.isNullOrEmpty() && uri.rawPath != "/") ||
            uri.rawQuery != null
        ) {
            return null
        }

        val rawFragment = uri.rawFragment ?: return null
        if (rawFragment.length > 12_000) return null

        val values = linkedMapOf<String, String>()
        rawFragment.split('&').forEach { component ->
            if (component.isBlank()) return@forEach
            val separator = component.indexOf('=')
            if (separator <= 0) return null
            val key = URLDecoder.decode(component.substring(0, separator), Charsets.UTF_8.name())
            val value = URLDecoder.decode(component.substring(separator + 1), Charsets.UTF_8.name())
            if (key.isBlank() || values.containsKey(key)) return null
            values[key] = value
        }

        val type = values["type"] ?: return null
        if (type !in setOf("signup", "recovery")) return null

        val accessToken = values["access_token"] ?: return null
        val refreshToken = values["refresh_token"] ?: return null
        if (!accessToken.matches(Regex("[A-Za-z0-9._-]{24,8192}")) ||
            !refreshToken.matches(Regex("[A-Za-z0-9._~-]{20,4096}"))
        ) {
            return null
        }

        val expiresAt = values["expires_at"]?.toLongOrNull()
            ?: values["expires_in"]?.toLongOrNull()?.let { nowEpochSeconds + it }
            ?: return null
        if (expiresAt <= nowEpochSeconds || expiresAt - nowEpochSeconds > 7 * 24 * 60 * 60) {
            return null
        }

        SupabaseAuthCallback(
            accessToken = accessToken,
            refreshToken = refreshToken,
            type = type,
            expiresAtEpochSeconds = expiresAt
        )
    } catch (_: Exception) {
        null
    }
}
