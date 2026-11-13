package com.kavoryn.app

import android.content.Context
import android.net.Uri
import android.provider.OpenableColumns
import android.webkit.MimeTypeMap
import java.util.Locale

data class SelectedDocument(
    val uri: Uri,
    val displayName: String,
    val mimeType: String,
    val sizeBytes: Long
) {
    val sizeLabel: String
        get() = if (sizeBytes < 1024L * 1024L) {
            String.format(Locale.US, "%.1f KB", sizeBytes / 1024.0)
        } else {
            String.format(Locale.US, "%.2f MB", sizeBytes / (1024.0 * 1024.0))
        }
}

sealed interface DocumentMetadataResult {
    data class Valid(val document: SelectedDocument) : DocumentMetadataResult
    data class Invalid(val reason: String) : DocumentMetadataResult
}

fun readDocumentMetadata(context: Context, uri: Uri): DocumentMetadataResult {
    val resolver = context.contentResolver
    val mime = resolver.getType(uri)?.lowercase(Locale.ROOT)
        ?: return DocumentMetadataResult.Invalid("The selected file type could not be verified.")
    val allowed = mime == "application/pdf" ||
        mime == "image/jpeg" || mime == "image/png" || mime == "image/webp"
    if (!allowed) {
        return DocumentMetadataResult.Invalid("Unsupported file type. Choose a PDF, JPG, PNG or WebP image.")
    }

    var name = "Document"
    var size = -1L
    resolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE), null, null, null)
        ?.use { cursor ->
            if (cursor.moveToFirst()) {
                val nameIndex = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME)
                val sizeIndex = cursor.getColumnIndex(OpenableColumns.SIZE)
                if (nameIndex >= 0) name = cursor.getString(nameIndex) ?: name
                if (sizeIndex >= 0 && !cursor.isNull(sizeIndex)) size = cursor.getLong(sizeIndex)
            }
        }

    if (size == 0L) {
        return DocumentMetadataResult.Invalid("The selected file is empty.")
    }
    return DocumentMetadataResult.Valid(SelectedDocument(uri, name, mime, size))
}
