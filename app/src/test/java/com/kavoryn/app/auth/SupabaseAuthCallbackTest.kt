package com.kavoryn.app.auth

import com.kavoryn.app.BuildConfig
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class SupabaseAuthCallbackTest {
    private val appCallback = KAVORYN_AUTH_REDIRECT_URI
    private val access = "eyJhbGciOiJIUzI1NiJ9.payload.signature"
    private val refresh = "refresh_token_value_123456789"

    @Test
    fun acceptsRecoveryCallbackOnlyForConfiguredAppUri() {
        val callback = parseSupabaseAuthCallback(
            "$appCallback#access_token=$access&refresh_token=$refresh&type=recovery&expires_in=3600",
            nowEpochSeconds = 1_000
        )

        requireNotNull(callback)
        assertEquals(access, callback.accessToken)
        assertEquals(refresh, callback.refreshToken)
        assertEquals("recovery", callback.type)
        assertEquals(4_600L, callback.expiresAtEpochSeconds)
    }

    @Test
    fun acceptsSignupConfirmationCallback() {
        val callback = parseSupabaseAuthCallback(
            "$appCallback#access_token=$access&refresh_token=$refresh&type=signup&expires_at=5000",
            nowEpochSeconds = 1_000
        )

        requireNotNull(callback)
        assertEquals("signup", callback.type)
        assertEquals(5_000L, callback.expiresAtEpochSeconds)
    }

    @Test
    fun rejectsOtherSchemesAndHosts() {
        assertNull(parseSupabaseAuthCallback(
            "https://example.com/auth-callback#access_token=$access&refresh_token=$refresh&type=recovery&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
        assertNull(parseSupabaseAuthCallback(
            "${BuildConfig.APPLICATION_ID}://other-host#access_token=$access&refresh_token=$refresh&type=recovery&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
        assertNull(parseSupabaseAuthCallback(
            "com.kavoryn.app://auth-callback#access_token=$access&refresh_token=$refresh&type=recovery&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
    }

    @Test
    fun rejectsQueryTokensAndUnsupportedCallbackTypes() {
        assertNull(parseSupabaseAuthCallback(
            "$appCallback?access_token=$access&refresh_token=$refresh&type=recovery",
            nowEpochSeconds = 1_000
        ))
        assertNull(parseSupabaseAuthCallback(
            "$appCallback#access_token=$access&refresh_token=$refresh&type=magiclink&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
    }

    @Test
    fun rejectsMissingDuplicateExpiredOrMalformedTokens() {
        assertNull(parseSupabaseAuthCallback(
            "$appCallback#access_token=$access&type=recovery&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
        assertNull(parseSupabaseAuthCallback(
            "$appCallback#access_token=$access&access_token=$access&refresh_token=$refresh&type=recovery&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
        assertNull(parseSupabaseAuthCallback(
            "$appCallback#access_token=$access&refresh_token=$refresh&type=recovery&expires_at=999",
            nowEpochSeconds = 1_000
        ))
        assertNull(parseSupabaseAuthCallback(
            "$appCallback#access_token=short&refresh_token=$refresh&type=recovery&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
    }


    @Test
    fun rejectsSpoofedAuthoritiesPortsPathsAndQueries() {
        val fragment =
            "access_token=$access&refresh_token=$refresh&type=recovery&expires_in=3600"
        val invalidUris = listOf(
            "${BuildConfig.APPLICATION_ID}://attacker@auth-callback#$fragment",
            "$appCallback:443#$fragment",
            "$appCallback/unexpected#$fragment",
            "$appCallback?next=https://example.com#$fragment"
        )

        invalidUris.forEach { uri ->
            assertNull(parseSupabaseAuthCallback(uri, nowEpochSeconds = 1_000))
        }
    }

    @Test
    fun rejectsMalformedPercentEncodingAndDuplicateDecodedKeys() {
        assertNull(parseSupabaseAuthCallback(
            "$appCallback#access_token=%ZZ&refresh_token=$refresh&type=recovery&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
        assertNull(parseSupabaseAuthCallback(
            "$appCallback#access_token=$access&%61ccess_token=$access&refresh_token=$refresh&type=recovery&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
    }

    @Test
    fun rejectsCallbacksWithAnUnreasonablyLongLifetime() {
        assertNull(parseSupabaseAuthCallback(
            "$appCallback#access_token=$access&refresh_token=$refresh&type=recovery&expires_in=999999999",
            nowEpochSeconds = 1_000
        ))
    }
}
