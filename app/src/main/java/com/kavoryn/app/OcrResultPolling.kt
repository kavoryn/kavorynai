package com.kavoryn.app

import kotlinx.coroutines.delay

/**
 * Waits for an owner-authenticated OCR result without unbounded polling.
 * Only HTTP 404 means "not ready"; all other failures stop immediately.
 */
internal suspend fun awaitOcrResult(
    maxAttempts: Int = DEFAULT_OCR_POLL_ATTEMPTS,
    intervalMs: Long = DEFAULT_OCR_POLL_INTERVAL_MS,
    fetch: suspend () -> DocumentOcrResult,
    wait: suspend (Long) -> Unit = { delay(it) }
): DocumentOcrResult {
    require(maxAttempts > 0) { "At least one OCR result attempt is required." }
    require(intervalMs >= 0) { "The OCR polling interval cannot be negative." }

    var lastNotReady: DocumentOcrResultApiException? = null
    for (attempt in 0 until maxAttempts) {
        try {
            return fetch()
        } catch (failure: DocumentOcrResultApiException) {
            if (failure.httpStatus != 404) throw failure
            lastNotReady = failure
            if (attempt < maxAttempts - 1 && intervalMs > 0) wait(intervalMs)
        }
    }
    throw lastNotReady ?: IllegalStateException("OCR result is not available yet.")
}

internal const val DEFAULT_OCR_POLL_ATTEMPTS = 30
internal const val DEFAULT_OCR_POLL_INTERVAL_MS = 2_000L
