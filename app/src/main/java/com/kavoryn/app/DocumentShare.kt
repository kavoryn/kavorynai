package com.kavoryn.app

import android.content.Intent

/**
 * Builds a text-only Android share intent from the OCR text the user chose to share.
 * The caller must invoke the chooser only in response to an explicit user action.
 */
internal fun createDocumentShareIntent(text: String): Intent {
    require(text.isNotBlank()) { "There is no extracted text to share." }
    return Intent(Intent.ACTION_SEND)
        .setType("text/plain")
        .putExtra(Intent.EXTRA_TEXT, text)
}
