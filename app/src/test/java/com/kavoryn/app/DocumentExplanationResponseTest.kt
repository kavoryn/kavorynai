package com.kavoryn.app

import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class DocumentExplanationResponseTest {
    private val documentId = "a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11"
    private val originalText = "Applicant: Aisha Bello. Payment due: 15 March 2027. Amount: NGN 45,000."

    @Test
    fun parsesKeyDetailsOnlyWhenExactQuotesExistInOriginalOcr() {
        val result = parseDocumentExplanationResponse(
            responseBody = validResponse().toString(),
            expectedDocumentId = documentId,
            originalOcrText = originalText,
            provider = ExplanationProvider.GEMINI,
            language = ExplanationLanguage.HAUSA
        )

        assertEquals(documentId, result.documentId)
        assertEquals(ExplanationProvider.GEMINI, result.provider)
        assertEquals(ExplanationLanguage.HAUSA, result.language)
        assertEquals(3, result.keyFacts.size)
        assertEquals("Aisha Bello", result.keyFacts[0].value)
        assertTrue(originalText.contains(result.keyFacts[0].sourceQuote))
    }

    @Test
    fun rejectsExplanationForDifferentDocument() {
        val wrongId = "b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11"
        val rejected = runCatching {
            parseDocumentExplanationResponse(
                validResponse(wrongId).toString(),
                documentId,
                originalText,
                ExplanationProvider.OPENAI,
                ExplanationLanguage.ENGLISH
            )
        }.isFailure
        assertTrue(rejected)
    }

    @Test
    fun rejectsKeyFactWithQuoteNotPresentInOriginalOcr() {
        val response = validResponse()
        val facts = response.getJSONArray("key_facts")
        facts.put(
            JSONObject()
                .put("label", "Account number")
                .put("value", "0011223344")
                .put("source_quote", "Account number: 0011223344")
        )
        val rejected = runCatching {
            parseDocumentExplanationResponse(
                response.toString(),
                documentId,
                originalText,
                ExplanationProvider.OPENAI,
                ExplanationLanguage.ENGLISH
            )
        }.isFailure
        assertTrue(rejected)
    }

    @Test
    fun rejectsBlankOrUnsupportedKeyFacts() {
        val response = validResponse()
        response.getJSONArray("key_facts").put(
            JSONObject()
                .put("label", " ")
                .put("value", "Some value")
                .put("source_quote", "Applicant: Aisha Bello")
        )
        val rejected = runCatching {
            parseDocumentExplanationResponse(
                response.toString(),
                documentId,
                originalText,
                ExplanationProvider.OPENAI,
                ExplanationLanguage.ENGLISH
            )
        }.isFailure
        assertTrue(rejected)
    }

    @Test
    fun acceptsNoKeyFactsWithoutInventingAny() {
        val response = validResponse().put("key_facts", JSONArray())
        val result = parseDocumentExplanationResponse(
            response.toString(),
            documentId,
            originalText,
            ExplanationProvider.OPENAI,
            ExplanationLanguage.ENGLISH
        )
        assertTrue(result.keyFacts.isEmpty())
    }

    private fun validResponse(id: String = documentId) = JSONObject()
        .put("document_id", id)
        .put("summary", "This document identifies an applicant and a payment.")
        .put("detailed_explanation", "The application names the applicant and gives a payment deadline and amount.")
        .put(
            "actions",
            JSONArray()
                .put("Check the payment details on the original document.")
                .put("Contact the issuing organisation if anything is unclear.")
        )
        .put(
            "key_facts",
            JSONArray()
                .put(
                    JSONObject()
                        .put("label", "Applicant")
                        .put("value", "Aisha Bello")
                        .put("source_quote", "Aisha Bello")
                )
                .put(
                    JSONObject()
                        .put("label", "Payment deadline")
                        .put("value", "15 March 2027")
                        .put("source_quote", "15 March 2027")
                )
                .put(
                    JSONObject()
                        .put("label", "Amount")
                        .put("value", "NGN 45,000")
                        .put("source_quote", "NGN 45,000")
                )
        )
        .put("request_id", "req_test_01")
}
