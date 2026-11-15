package com.kavoryn.app

import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

class DocumentDeleteApiTest {
    private val validId = "123e4567-e89b-12d3-a456-426614174000"

    @Test
    fun acceptsHttpsAndAuthenticatedRequest() {
        validateDocumentDeleteRequest("https://api.example.test/", validId, "access-token")
    }

    @Test
    fun rejectsHttpApiBeforeNetworkAccess() {
        val error = assertThrows(IllegalArgumentException::class.java) {
            validateDocumentDeleteRequest("http://api.example.test", validId, "access-token")
        }
        assertEquals("The Kavoryn API must use HTTPS.", error.message)
    }

    @Test
    fun rejectsMissingSessionBeforeNetworkAccess() {
        val error = assertThrows(IllegalArgumentException::class.java) {
            validateDocumentDeleteRequest("https://api.example.test", validId, " ")
        }
        assertEquals("A signed-in session is required.", error.message)
    }

    @Test
    fun rejectsInvalidDocumentIdentifierBeforeNetworkAccess() {
        assertThrows(IllegalArgumentException::class.java) {
            validateDocumentDeleteRequest("https://api.example.test", "../other-user-document", "token")
        }
    }
}
