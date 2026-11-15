package com.kavoryn.app.auth

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class SupabaseAuthCallbackTest {
    private val access = "eyJhbGciOiJIUzI1NiJ9.payload.signature"
    private val refresh = "refresh_token_value_123456789"

    @Test
    fun acceptsRecoveryCallbackOnlyForConfiguredAppUri() {
        val callback = parseSupabaseAuthCallback(
            "com.kavoryn.app://auth-callback#access_token=$access&refresh_token=$refresh&type=recovery&expires_in=3600",
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
            "com.kavoryn.app://auth-callback#access_token=$access&refresh_token=$refresh&type=signup&expires_at=5000",
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
            "com.kavoryn.app://other-host#access_token=$access&refresh_token=$refresh&type=recovery&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
    }

    @Test
    fun rejectsQueryTokensAndUnsupportedCallbackTypes() {
        assertNull(parseSupabaseAuthCallback(
            "com.kavoryn.app://auth-callback?access_token=$access&refresh_token=$refresh&type=recovery",
            nowEpochSeconds = 1_000
        ))
        assertNull(parseSupabaseAuthCallback(
            "com.kavoryn.app://auth-callback#access_token=$access&refresh_token=$refresh&type=magiclink&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
    }

    @Test
    fun rejectsMissingDuplicateExpiredOrMalformedTokens() {
        assertNull(parseSupabaseAuthCallback(
            "com.kavoryn.app://auth-callback#access_token=$access&type=recovery&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
        assertNull(parseSupabaseAuthCallback(
            "com.kavoryn.app://auth-callback#access_token=$access&access_token=$access&refresh_token=$refresh&type=recovery&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
        assertNull(parseSupabaseAuthCallback(
            "com.kavoryn.app://auth-callback#access_token=$access&refresh_token=$refresh&type=recovery&expires_at=999",
            nowEpochSeconds = 1_000
        ))
        assertNull(parseSupabaseAuthCallback(
            "com.kavoryn.app://auth-callback#access_token=short&refresh_token=$refresh&type=recovery&expires_in=3600",
            nowEpochSeconds = 1_000
        ))
    }

    @Test
    fun rejectsCallbacksWithAnUnreasonablyLongLifetime() {
        assertNull(parseSupabaseAuthCallback(
            "com.kavoryn.app://auth-callback#access_token=$access&refresh_token=$refresh&type=recovery&expires_in=999999999",
            nowEpochSeconds = 1_000
        ))
    }
}
