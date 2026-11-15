package com.kavoryn.app

import android.app.Activity
import android.content.Context
import android.content.Intent

/**
 * Policy for the explicit Android share-sheet action.
 * The original OCR text is preserved exactly; no private source file is attached.
 */
object DocumentSharePolicy {
    const val MAX_SHARE_TEXT_CHARS = 200_000

    fun validationError(text: String): String? = when {
        text.isBlank() -> "There is no extracted text to share."
        text.length > MAX_SHARE_TEXT_CHARS -> "This extracted text is too long to share in one message."
        else -> null
    }

    fun textForSharing(text: String): String {
        val error = validationError(text)
        require(error == null) { error ?: "The extracted text cannot be shared." }
        return text
    }
}

/**
 * Opens the Android system chooser. Kavoryn does not select an app or recipient,
 * and no document file or private OCR attachment is provided.
 */
fun shareDocumentText(context: Context, originalOcrText: String) {
    val text = DocumentSharePolicy.textForSharing(originalOcrText)
    val sendIntent = Intent(Intent.ACTION_SEND).apply {
        type = "text/plain"
        putExtra(Intent.EXTRA_SUBJECT, "Extracted text from Kavoryn AI")
        putExtra(Intent.EXTRA_TEXT, text)
    }
    val chooser = Intent.createChooser(sendIntent, "Share extracted text")
    if (context !is Activity) {
        chooser.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    }
    context.startActivity(chooser)
}
