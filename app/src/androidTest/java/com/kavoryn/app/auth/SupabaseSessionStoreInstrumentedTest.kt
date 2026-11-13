package com.kavoryn.app.auth

import android.content.Context
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class SupabaseSessionStoreInstrumentedTest {
    private lateinit var context: Context
    private lateinit var store: SupabaseSessionStore

    @Before
    fun setUp() {
        context = InstrumentationRegistry.getInstrumentation().targetContext
        store = SupabaseSessionStore(context)
        store.clear()
    }

    @Test
    fun roundTripsSessionAndKeepsTokensOutOfPlaintextPreferences() {
        val session = SupabaseAuthSession(
            accessToken = "access-token-test-value",
            refreshToken = "refresh-token-test-value",
            tokenType = "bearer",
            expiresAtEpochSeconds = 1_900_000_000L,
            userId = "user-123",
            email = "reader@example.com"
        )

        store.save(session)

        val preferences = context.getSharedPreferences(
            "kavoryn_secure_session",
            Context.MODE_PRIVATE
        )
        val ciphertext = preferences.getString("session_ciphertext", null)
        val iv = preferences.getString("session_iv", null)

        assertFalse(ciphertext.isNullOrBlank())
        assertFalse(iv.isNullOrBlank())
        assertFalse(ciphertext!!.contains(session.accessToken))
        assertFalse(ciphertext.contains(session.refreshToken))
        assertEquals(session, store.read())
    }

    @Test
    fun tamperedCiphertextIsRejectedAndRemoved() {
        store.save(sampleSession())
        val preferences = context.getSharedPreferences(
            "kavoryn_secure_session",
            Context.MODE_PRIVATE
        )
        val ciphertext = requireNotNull(preferences.getString("session_ciphertext", null))
        val tampered = (if (ciphertext.first() == 'A') 'B' else 'A') + ciphertext.drop(1)
        preferences.edit().putString("session_ciphertext", tampered).commit()

        assertNull(store.read())
        assertTrue(preferences.all.isEmpty())
    }

    @Test
    fun incompleteEncryptedRecordIsCleared() {
        store.save(sampleSession())
        val preferences = context.getSharedPreferences(
            "kavoryn_secure_session",
            Context.MODE_PRIVATE
        )
        preferences.edit().remove("session_ciphertext").commit()

        assertNull(store.read())
        assertTrue(preferences.all.isEmpty())
    }

    @Test
    fun clearRemovesPersistedSession() {
        store.save(sampleSession())
        store.clear()

        assertNull(store.read())
        val preferences = context.getSharedPreferences(
            "kavoryn_secure_session",
            Context.MODE_PRIVATE
        )
        assertTrue(preferences.all.isEmpty())
    }

    private fun sampleSession() = SupabaseAuthSession(
        accessToken = "access-token",
        refreshToken = "refresh-token",
        tokenType = "bearer",
        expiresAtEpochSeconds = 1_900_000_000L,
        userId = "user-123",
        email = null
    )
}
