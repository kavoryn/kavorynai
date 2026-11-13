package com.kavoryn.app.auth

import android.content.Context
import android.util.Base64
import org.json.JSONObject
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties

/**
 * Stores only the current user's Supabase session, encrypted with an Android Keystore
 * AES-256-GCM key. No token is written to logs, files, or plaintext preferences.
 */
class SupabaseSessionStore(context: Context) {
    private val preferences = context.applicationContext.getSharedPreferences(
        PREFERENCES_NAME, Context.MODE_PRIVATE
    )

    @Synchronized
    fun save(session: SupabaseAuthSession) {
        require(
            session.accessToken.isNotBlank() && session.refreshToken.isNotBlank() &&
                session.userId.isNotBlank()
        ) { "Cannot store an incomplete authentication session." }

        val payload = JSONObject()
            .put("access_token", session.accessToken)
            .put("refresh_token", session.refreshToken)
            .put("token_type", session.tokenType)
            .put("expires_at", session.expiresAtEpochSeconds)
            .put("user_id", session.userId)
            .put("email", session.email)
            .toString()
            .toByteArray(Charsets.UTF_8)

        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey())
        val encrypted = cipher.doFinal(payload)
        val iv = cipher.iv
        val encodedIv = Base64.encodeToString(iv, Base64.NO_WRAP)
        val encodedCiphertext = Base64.encodeToString(encrypted, Base64.NO_WRAP)
        check(
            preferences.edit()
                .putString(KEY_IV, encodedIv)
                .putString(KEY_CIPHERTEXT, encodedCiphertext)
                .commit()
        ) { "The authentication session could not be saved securely." }
    }

    @Synchronized
    fun read(): SupabaseAuthSession? {
        val encodedIv = preferences.getString(KEY_IV, null)
        val encodedCiphertext = preferences.getString(KEY_CIPHERTEXT, null)
        if (encodedIv == null && encodedCiphertext == null) return null
        if (encodedIv.isNullOrBlank() || encodedCiphertext.isNullOrBlank()) {
            clear()
            return null
        }
        return try {
            val cipher = Cipher.getInstance(TRANSFORMATION)
            cipher.init(
                Cipher.DECRYPT_MODE,
                getOrCreateKey(),
                GCMParameterSpec(GCM_TAG_LENGTH_BITS, Base64.decode(encodedIv, Base64.NO_WRAP))
            )
            val plaintext = cipher.doFinal(
                Base64.decode(encodedCiphertext, Base64.NO_WRAP)
            )
            val json = JSONObject(plaintext.toString(Charsets.UTF_8))
            SupabaseAuthSession(
                accessToken = json.getString("access_token"),
                refreshToken = json.getString("refresh_token"),
                tokenType = json.optString("token_type", "bearer"),
                expiresAtEpochSeconds = json.getLong("expires_at"),
                userId = json.getString("user_id"),
                email = json.optString("email").takeIf { it.isNotBlank() }
            )
        } catch (_: Exception) {
            clear()
            null
        }
    }

    @Synchronized
    fun clear() {
        preferences.edit().clear().commit()
    }

    private fun getOrCreateKey(): SecretKey {
        val keyStore = KeyStore.getInstance(ANDROID_KEY_STORE).apply { load(null) }
        (keyStore.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }

        val generator = KeyGenerator.getInstance(
            KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEY_STORE
        )
        generator.init(
            KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .setRandomizedEncryptionRequired(true)
                .build()
        )
        return generator.generateKey()
    }

    private companion object {
        const val ANDROID_KEY_STORE = "AndroidKeyStore"
        const val KEY_ALIAS = "kavoryn_supabase_session_v1"
        const val PREFERENCES_NAME = "kavoryn_secure_session"
        const val KEY_IV = "session_iv"
        const val KEY_CIPHERTEXT = "session_ciphertext"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
        const val GCM_TAG_LENGTH_BITS = 128
    }
}
