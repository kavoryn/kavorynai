package com.kavoryn.app

import android.content.Context
import android.graphics.BitmapFactory
import android.graphics.pdf.PdfRenderer
import android.net.Uri
import android.os.ParcelFileDescriptor

sealed interface DocumentInspection {
    data class Pdf(val pageCount: Int) : DocumentInspection
    data class Image(val width: Int, val height: Int, val qualityNote: String?) : DocumentInspection
    data class Problem(val message: String) : DocumentInspection
}

/**
 * Performs local-only structural checks. No file bytes leave the device.
 * Product page/file limits are intentionally not hard-coded here.
 */
fun inspectDocument(context: Context, uri: Uri, mimeType: String): DocumentInspection {
    return try {
        if (mimeType == "application/pdf") {
            val descriptor = context.contentResolver.openFileDescriptor(uri, "r")
                ?: return DocumentInspection.Problem("PDF could not be opened.")
            descriptor.use { pfd ->
                PdfRenderer(pfd).use { renderer ->
                    if (renderer.pageCount < 1) {
                        DocumentInspection.Problem("This PDF has no readable pages.")
                    } else {
                        DocumentInspection.Pdf(renderer.pageCount)
                    }
                }
            }
        } else {
            val options = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            context.contentResolver.openInputStream(uri)?.use { input ->
                BitmapFactory.decodeStream(input, null, options)
            } ?: return DocumentInspection.Problem("Image could not be opened.")
            if (options.outWidth <= 0 || options.outHeight <= 0) {
                DocumentInspection.Problem("The image appears damaged or unreadable.")
            } else {
                val note = if (minOf(options.outWidth, options.outHeight) < 640) {
                    "Low image resolution may reduce text-recognition accuracy."
                } else null
                DocumentInspection.Image(options.outWidth, options.outHeight, note)
            }
        }
    } catch (_: Exception) {
        DocumentInspection.Problem("The file could not be fully inspected on this device.")
    }
}
