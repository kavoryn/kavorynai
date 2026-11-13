package com.kavoryn.app.auth

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Assert.assertFalse
import org.junit.Test

class SupabaseAuthApiTest {
    @Test
    fun projectUrlMustUseHttpsAndTrailingSlashIsRemoved() {
        assertEquals(
            "https://project.supabase.co",
            validatedSupabaseAuthRoot(" https://project.supabase.co/ ")
        )
        assertThrows(IllegalArgumentException::class.java) {
            validatedSupabaseAuthRoot("http://project.supabase.co")
        }
        assertThrows(IllegalArgumentException::class.java) {
            validatedSupabaseAuthRoot("https://")
        }
        assertThrows(IllegalArgumentException::class.java) {
            validatedSupabaseAuthRoot("https://project.supabase.co/unexpected/path")
        }
        assertThrows(IllegalArgumentException::class.java) {
            validatedSupabaseAuthRoot("https://user@project.supabase.co")
        }
    }

    @Test
    fun parsesSessionAndUsesServerExpiry() {
        val session = parseSupabaseAuthSession(
            """{
                "access_token":"access",
                "refresh_token":"refresh",
                "token_type":"bearer",
                "expires_at":1900000000,
                "expires_in":3600,
                "user":{"id":"user-123","email":"reader@example.com"}
            }""",
            nowEpochSeconds = 1800000000
        )

        assertEquals("access", session.accessToken)
        assertEquals("refresh", session.refreshToken)
        assertEquals("bearer", session.tokenType)
        assertEquals(1900000000L, session.expiresAtEpochSeconds)
        assertEquals("user-123", session.userId)
        assertEquals("reader@example.com", session.email)
    }

    @Test
    fun derivesExpiryWhenServerDoesNotProvideExpiresAt() {
        val session = parseSupabaseAuthSession(
            """{
                "access_token":"access",
                "refresh_token":"refresh",
                "expires_in":120,
                "user":{"id":"user-123"}
            }""",
            nowEpochSeconds = 1000
        )

        assertEquals(1120L, session.expiresAtEpochSeconds)
        assertNull(session.email)
    }

    @Test
    fun onlyRejectedRefreshCredentialsClearTheSavedSession() {
        assertTrue(shouldClearStoredSessionAfterRefreshFailure(
            SupabaseAuthException(400, "invalid_grant", "Rejected")
        ))
        assertTrue(shouldClearStoredSessionAfterRefreshFailure(
            SupabaseAuthException(401, "unauthorized", "Rejected")
        ))
        assertFalse(shouldClearStoredSessionAfterRefreshFailure(
            SupabaseAuthException(429, null, "Rate limited")
        ))
        assertFalse(shouldClearStoredSessionAfterRefreshFailure(
            SupabaseAuthException(503, null, "Unavailable")
        ))
        assertFalse(shouldClearStoredSessionAfterRefreshFailure(
            SupabaseAuthException(null, null, "Network error")
        ))
    }

    @Test
    fun rejectsIncompleteSession() {
        assertThrows(Exception::class.java) {
            parseSupabaseAuthSession(
                """{"access_token":"access","refresh_token":"","user":{"id":"user-123"}}"""
            )
        }
    }
}
